<?php
/**
 * 用户业务服务：注册 / 登录 / 改密 / 找回 / 资料
 * 核心身份数据全部经由 UCenter API，本地仅缓存扩展资料
 */
class UserService
{
    /** @var UCenterClient */
    private static $uc = null;

    public static function uc()
    {
        if (self::$uc === null) {
            self::$uc = new UCenterClient();
        }
        return self::$uc;
    }

    /**
     * 安全调用 UCenter：UCenter 不可达时返回 null 而非抛异常
     * （业务层各方法自行降级为友好提示，避免 500）
     */
    private static function ucSafe($method, ...$args)
    {
        try {
            return self::uc()->$method(...$args);
        } catch (Exception $e) {
            Logger::warn('ucenter', 'UCenter 调用降级失败: ' . $method . ' → ' . $e->getMessage());
            return null;
        }
    }

    // ==================== 注册 ====================

    /**
     * @param array $in username/password/confirm/email/mobile/nickname
     * @param bool  $autoLogin
     */
    public static function register(array $in, $autoLogin = true, $force = false)
    {
        $username = trim($in['username'] ?? '');
        $password = (string)($in['password'] ?? '');
        $confirm  = (string)($in['confirm'] ?? '');
        $email    = trim($in['email'] ?? '');
        $mobile   = trim($in['mobile'] ?? '');
        $nickname = trim($in['nickname'] ?? '');

        Validator::reset();
        if (!Validator::username($username)) return self::err(Validator::first());
        if (!Validator::password($password)) return self::err(Validator::first());
        if ($password !== $confirm) return self::err('两次输入的密码不一致');
        if ($email !== '' && !Validator::email($email)) return self::err(Validator::first());
        if ($mobile !== '' && !Validator::mobile($mobile)) return self::err(Validator::first());

        // $force=true 用于第三方登录自动建号：不受「开放注册」开关限制
        if (!$force && !Config::get('register_enabled', true)) {
            return self::err('系统当前未开放注册');
        }

        // 用户名唯一性
        $chk = self::ucSafe('checkName', $username);
        if ($chk === null) return self::err('身份服务暂不可用，请稍后重试');
        if ($chk === -1) return self::err('用户名不合法');
        if ($chk === -2) return self::err('该用户名属于保留字，请更换');
        if ($chk === -3) return self::err('用户名已被占用');
        if ($chk !== 1) return self::err('用户名校验失败，UCenter 返回 ' . $chk);

        // 邮箱：UCenter 要求 email 唯一且必填，未填则生成占位邮箱
        $placeholder = false;
        if ($email === '') {
            $email = self::placeholderEmail($username);
            $placeholder = true;
        } else {
            $ce = self::ucSafe('checkEmail', $email);
            if ($ce === -4) return self::err('邮箱格式不正确');
            if ($ce === -6) return self::err('邮箱已被注册');
        }
        if ($mobile !== '') {
            if (ProfileModel::findByMobile($mobile)) {
                return self::err('手机号已被绑定');
            }
        }

        $uid = self::ucSafe('register', $username, $password, $email);
        if ($uid === null) return self::err('身份服务暂不可用，请稍后重试');
        if ($uid <= 0) {
            return self::err('UCenter 注册失败：' . self::uc()->errno($uid));
        }

        // 本地扩展资料
        ProfileModel::save($uid, [
            'uid'      => $uid,
            'username' => $username,
            'nickname' => $nickname ?: $username,
            'mobile'   => $mobile,
            'gender'   => intval($in['gender'] ?? 0),
            'sync_status' => 1,
        ]);

        Logger::info('register', '用户注册成功', [
            'uid' => $uid, 'username' => $username,
            'extra' => ['email' => $placeholder ? '(占位)' : $email, 'mobile' => $mobile],
        ]);

        $sid = null;
        if ($autoLogin) {
            $sid = UserSession::create($uid, $username, false, '', 'center');
        }
        return ['ok' => true, 'uid' => $uid, 'sid' => $sid, 'placeholder_email' => $placeholder];
    }

    private static function placeholderEmail($username)
    {
        return 'u' . substr(md5($username . microtime(true)), 0, 10) . '@no-email.uic.local';
    }

    // ==================== 登录 ====================

    public static function login($account, $password, $remember = false, $appId = '')
    {
        $account = trim($account);
        $app     = null;
        if ($appId !== '') {
            $app = AppModel::findByAppId($appId);
            if (!$app || !$app['status']) {
                return self::err('应用不存在或已被禁用');
            }
        }

        // 限流（L1：统一用规范锁定键，防止手机号/用户名多标识分散计数绕过锁定）
        $lockKey = self::lockKey($account);
        $lock    = self::checkLock($lockKey);
        if ($lock !== true) return self::err($lock);

        $type = Validator::accountType($account);
        Validator::reset();

        // 手机号 -> 先查本地 profile 得到 uid，再取 UCenter 用户名
        if ($type === 'mobile') {
            $p = ProfileModel::findByMobile($account);
            if (!$p) return self::failLogin($lockKey, '该手机号未绑定任何账号');
            $info = self::ucSafe('getByUid', $p['uid']);
            if (empty($info)) return self::failLogin($lockKey, '账号不存在');
            $account = $info[1];
        }

        $res = self::ucSafe('login', $account, $password);
        if ($res === null) {
            return self::failLogin($lockKey, '身份服务暂不可用，请稍后重试');
        }
        if (!is_array($res)) {
            return self::failLogin($lockKey, self::loginErrMsg($res));
        }
        list($uid, $username, $passHash, $email) = array_pad($res, 4, '');
        $uid = intval($uid);
        if ($uid <= 0) {
            return self::failLogin($lockKey, self::loginErrMsg($uid));
        }

        // 与 UCenter 用户状态校验
        $uInfo = self::ucSafe('getByUid', $uid);
        if (empty($uInfo)) {
            return self::failLogin($lockKey, 'UCenter 中查不到该用户，账号可能已被删除');
        }

        self::clearLock($lockKey);

        // 首次登录补齐本地资料
        if (!ProfileModel::get($uid)) {
            ProfileModel::save($uid, [
                'uid' => $uid, 'username' => $username,
                'nickname' => $username, 'sync_status' => 1,
            ]);
        }

        $sid = UserSession::create($uid, $username, $remember, $appId, $appId ? 'app' : 'center');
        Logger::info('login', '用户登录成功', [
            'uid' => $uid, 'username' => $username, 'app_id' => $appId,
            'extra' => ['remember' => $remember, 'type' => $type, 'ua' => UserSession::deviceLabel()],
        ]);
        return ['ok' => true, 'uid' => $uid, 'username' => $username, 'sid' => $sid];
    }

    private static function loginErrMsg($code)
    {
        $map = [
            -1 => '账号不存在或已被删除',
            -2 => '密码错误',
            -3 => '安全提问回答错误',
        ];
        return $map[$code] ?? ('登录失败（UCenter 返回 ' . $code . '）');
    }

    // --- 登录失败限流 ---

    /** L1：锁定键归一——手机号尽量解析为 UCenter 用户名，防止多标识分散计数绕过锁定 */
    private static function lockKey($account)
    {
        $account = trim((string)$account);
        // 仅手机号可可靠归一（本地 profile → uid → UCenter 用户名）
        if (!preg_match('/^1[3-9]\d{9}$/', $account)) {
            return $account;
        }
        try {
            $p = ProfileModel::findByMobile($account);
            if ($p) {
                $info = self::ucSafe('getByUid', intval($p['uid']));
                if (!empty($info) && !empty($info[1])) {
                    return (string)$info[1];
                }
            }
        } catch (Exception $e) {
            // UCenter 异常时按原手机号锁定，保证限流不失效
        }
        return $account;
    }

    private static function checkLock($account)
    {
        $row = Database::getInstance()->one("SELECT * FROM login_fail WHERE account=?", [$account]);
        if (!$row) return true;
        if (intval($row['lock_until']) > time()) {
            return '登录失败次数过多，请 ' . ceil((intval($row['lock_until']) - time()) / 60) . ' 分钟后重试';
        }
        return true;
    }

    private static function failLogin($account, $msg)
    {
        $db = Database::getInstance();
        // 先 UPDATE 再 INSERT IGNORE（MySQL 5.7 兼容）
        // 注意：必须走 $db->prepare()，getPdo()->prepare() 会绕过表前缀注入
        $now = time();
        $ip  = Request::ip();
        $st  = $db->prepare("UPDATE login_fail SET fail_count=fail_count+1, last_at=?, last_ip=? WHERE account=?");
        $st->execute([$now, $ip, $account]);
        if ($st->rowCount() === 0) {
            $db->prepare(
                "INSERT IGNORE INTO login_fail(account,fail_count,lock_until,last_at,last_ip) VALUES(?,1,0,?,?)"
            )->execute([$account, $now, $ip]);
        }
        $row = $db->one("SELECT * FROM login_fail WHERE account=?", [$account]);
        $limit = intval(Config::get('login_fail_limit', 5));
        if (intval($row['fail_count']) >= $limit) {
            $until = time() + intval(Config::get('login_lock_time', 900));
            $db->update('login_fail', ['lock_until' => $until, 'fail_count' => 0], 'account=?', [$account]);
        }
        Logger::warn('login', '登录失败：' . $msg, ['target' => $account, 'extra' => ['ip' => Request::ip()]]);
        return self::err($msg);
    }

    private static function clearLock($account)
    {
        Database::getInstance()->delete('login_fail', 'account=?', [$account]);
    }

    // ==================== 验证码登录 ====================

    /** 验证码登录 - 第一步：发送验证码到邮箱或手机号 */
    public static function sendLoginCode($target)
    {
        if (!intval(Config::get('login_code_enabled', 1))) {
            return self::err('验证码登录未开启');
        }
        $target = trim($target);
        $type   = Validator::accountType($target);
        Validator::reset();
        if ($type !== 'email' && $type !== 'mobile') {
            return self::err('请输入邮箱或手机号');
        }
        // 通道开关：邮箱 / 手机号可独立控制
        if ($type === 'email' && !intval(Config::get('login_code_email_enabled', 1))) {
            return self::err('邮箱验证码登录未开启');
        }
        if ($type === 'mobile' && !intval(Config::get('login_code_sms_enabled', 0))) {
            return self::err('手机号验证码登录未开启');
        }
        // 校验账号存在性
        if ($type === 'mobile') {
            $p = ProfileModel::findByMobile($target);
            if (!$p) return self::err('该手机号未绑定任何账号');
        } else {
            $info = self::ucSafe('getUser', $target);
            if (empty($info)) return self::err('该邮箱未绑定任何账号');
        }
        // 频率限制：同一目标发送间隔（防止短信/邮件轰炸）
        $interval = intval(Config::get('login_code_interval', 60));
        $last = Database::getInstance()->one(
            "SELECT * FROM verify_codes WHERE target=? AND scene='login' ORDER BY id DESC LIMIT 1",
            [$target]
        );
        if ($last && (time() - intval($last['created_at'])) < $interval) {
            $wait = $interval - (time() - intval($last['created_at']));
            return self::err('发送过于频繁，请 ' . $wait . ' 秒后重试');
        }
        $ttl = intval(Config::get('login_code_ttl', 300));
        $code = VerifyModel::issue($target, 'login', $ttl);
        list($ok, $msg) = VerifyModel::send($target, 'login', $code);
        if (!$ok) return self::err($msg);
        Logger::info('login', '发送验证码登录验证码', ['target' => $target, 'extra' => ['type' => $type]]);
        $ret = ['ok' => true, 'message' => $msg];
        if (Config::get('debug')) {
            $ret['debug_code'] = $code;
        }
        return $ret;
    }

    /** 验证码登录 - 第二步：校验验证码并创建会话 */
    public static function loginByCode($target, $code, $remember = false, $appId = '')
    {
        if (!intval(Config::get('login_code_enabled', 1))) {
            return self::err('验证码登录未开启');
        }
        $target = trim($target);
        $type   = Validator::accountType($target);
        Validator::reset();
        if ($type !== 'email' && $type !== 'mobile') {
            return self::err('请输入邮箱或手机号');
        }
        if ($type === 'email' && !intval(Config::get('login_code_email_enabled', 1))) {
            return self::err('邮箱验证码登录未开启');
        }
        if ($type === 'mobile' && !intval(Config::get('login_code_sms_enabled', 0))) {
            return self::err('手机号验证码登录未开启');
        }
        // 应用门禁
        $app = null;
        if ($appId !== '') {
            $app = AppModel::findByAppId($appId);
            if (!$app || !$app['status']) {
                return self::err('应用不存在或已被禁用');
            }
        }
        // 限流
        $lockKey = self::lockKey($target);
        $lock    = self::checkLock($lockKey);
        if ($lock !== true) return self::err($lock);

        // 校验验证码
        list($ok, $msg) = VerifyModel::check($target, 'login', $code);
        if (!$ok) return self::failLogin($lockKey, $msg);

        // 解析用户
        if ($type === 'mobile') {
            $p = ProfileModel::findByMobile($target);
            if (!$p) return self::failLogin($lockKey, '账号不存在');
            $info = self::ucSafe('getByUid', $p['uid']);
        } else {
            $info = self::ucSafe('getUser', $target);
        }
        if (empty($info)) return self::failLogin($lockKey, '账号不存在');
        list($uid, $username) = array_pad($info, 2, '');
        $uid = intval($uid);
        if ($uid <= 0) return self::failLogin($lockKey, '账号不存在');

        self::clearLock($lockKey);

        // 首次登录补齐本地资料
        if (!ProfileModel::get($uid)) {
            ProfileModel::save($uid, [
                'uid' => $uid, 'username' => $username,
                'nickname' => $username, 'sync_status' => 1,
            ]);
        }

        $sid = UserSession::create($uid, $username, $remember, $appId, $appId ? 'app' : 'center');
        Logger::info('login', '验证码登录成功', [
            'uid' => $uid, 'username' => $username, 'app_id' => $appId,
            'extra' => ['remember' => $remember, 'type' => $type, 'ua' => UserSession::deviceLabel()],
        ]);
        return ['ok' => true, 'uid' => $uid, 'username' => $username, 'sid' => $sid];
    }

    // ==================== 退出 ====================

    public static function logout($sid = '')
    {
        $cur = UserSession::current();
        if (!$cur) return self::err('当前未登录');
        // 仅允许退出「当前会话」或「同账号名下的其它会话」；
        // 传入非当前会话 sid 时必须校验归属，防止越权销毁他人会话（IDOR）。
        $target = $sid ?: $cur['sid'];
        if ($sid && $sid !== $cur['sid']) {
            $row = Database::getInstance()->one("SELECT * FROM sessions WHERE sid=?", [$sid]);
            if (!$row || intval($row['uid']) !== intval($cur['uid'])) {
                return self::err('无权退出该设备');
            }
        }
        $logSid = $target;
        UserSession::destroy($target);
        Logger::info('logout', '退出登录', ['uid' => $cur['uid'], 'username' => $cur['username'], 'extra' => ['sid' => substr($logSid, 0, 12)]]);
        return ['ok' => true];
    }

    public static function logoutAll($uid = 0)
    {
        $cur = UserSession::current();
        $uid = $uid ?: ($cur ? intval($cur['uid']) : 0);
        if (!$uid) return self::err('未登录');
        $except = $cur && intval($cur['uid']) === $uid ? $cur['sid'] : '';
        $n = UserSession::destroyAll($uid, $except);
        Logger::info('logout', '退出全部设备', ['uid' => $uid, 'extra' => ['count' => $n]]);
        return ['ok' => true, 'revoked' => $n];
    }

    // ==================== 修改密码 ====================

    public static function changePassword($uid, $oldPassword, $newPassword, $confirm)
    {
        if ($newPassword !== $confirm) return self::err('两次输入的新密码不一致');
        Validator::reset();
        if (!Validator::password($newPassword)) return self::err(Validator::first());
        if ($oldPassword === $newPassword) return self::err('新密码不能与旧密码相同');

        $info = self::ucSafe('getByUid', $uid);
        if (empty($info)) return self::err('账号不存在');
        $username = $info[1];

        $r = self::ucSafe('edit', $username, $oldPassword, $newPassword, '', 0);
        if ($r === null) return self::err('身份服务暂不可用，请稍后重试');
        if ($r < 0) return self::err('修改失败：' . self::uc()->errno($r === -1 ? -1 : $r));

        // 原登录状态失效
        UserSession::destroyAll($uid);
        Logger::info('password', '修改密码成功，全部设备已退出登录', ['uid' => $uid, 'username' => $username]);
        return ['ok' => true, 'relogin' => true];
    }

    // ==================== 找回密码 ====================

    /** 第一步：发送验证码 */
    public static function sendResetCode($target)
    {
        $target = trim($target);
        $type   = Validator::accountType($target);
        Validator::reset();
        if ($type !== 'email' && $type !== 'mobile') {
            return self::err('请输入已绑定的邮箱或手机号');
        }
        // 校验存在性
        if ($type === 'mobile') {
            $p = ProfileModel::findByMobile($target);
            if (!$p) return self::err('该手机号未绑定任何账号');
        } else {
            $info = self::ucSafe('getUser', $target);
            if (empty($info)) return self::err('该邮箱未绑定任何账号');
        }
        $code = VerifyModel::issue($target, 'findpwd');
        list($ok, $msg) = VerifyModel::send($target, 'findpwd', $code);
        if (!$ok) return self::err($msg);
        Logger::info('findpwd', '发送找回密码验证码', ['target' => $target]);
        $ret = ['ok' => true, 'message' => $msg];
        if (Config::get('debug')) {
            // 仅本地调试环境回显验证码；生产模式严禁将验证码下发给调用方（防账户接管）
            $ret['debug_code'] = $code;
        }
        return $ret;
    }

    /** 第二步：验证身份并重置 */
    public static function resetPassword($target, $code, $newPassword, $confirm)
    {
        if ($newPassword !== $confirm) return self::err('两次输入的新密码不一致');
        Validator::reset();
        if (!Validator::password($newPassword)) return self::err(Validator::first());

        list($ok, $msg) = VerifyModel::check($target, 'findpwd', $code);
        if (!$ok) return self::err($msg);

        $type = Validator::accountType($target);
        Validator::reset();
        if ($type === 'mobile') {
            $p = ProfileModel::findByMobile($target);
            if (!$p) return self::err('账号不存在');
            $info = self::ucSafe('getByUid', $p['uid']);
        } else {
            $info = self::ucSafe('getUser', $target);
        }
        if (empty($info)) return self::err('账号不存在');
        $uid = intval($info[0]);
        $username = $info[1];

        $r = self::ucSafe('edit', $username, '', $newPassword, '', 1);
        if ($r === null) return self::err('身份服务暂不可用，请稍后重试');
        if ($r < 0) return self::err('重置密码失败：' . self::uc()->errno($r));

        UserSession::destroyAll($uid);
        self::clearLock($username);
        Logger::info('findpwd', '通过找回密码重置成功，全部登录状态已失效', ['uid' => $uid, 'username' => $username]);
        return ['ok' => true, 'relogin' => true];
    }

    // ==================== 个人资料 ====================

    public static function updateProfile($uid, $data)
    {
        $info = self::ucSafe('getByUid', $uid);
        if (empty($info)) return self::err('账号不存在');
        list($uUid, $username, $email) = array_pad($info, 3, '');

        $allow = ['nickname', 'gender', 'birthday', 'bio', 'avatar', 'mobile'];
        $upd   = [];
        foreach ($allow as $k) {
            if (isset($data[$k])) {
                $upd[$k] = is_string($data[$k]) ? trim($data[$k]) : $data[$k];
            }
        }

        // 邮箱变更 -> UCenter
        $newEmail = null;
        if (isset($data['email']) && trim($data['email']) !== '' && trim($data['email']) !== $email) {
            $newEmail = trim($data['email']);
            Validator::reset();
            if (!Validator::email($newEmail)) return self::err(Validator::first());
            $r = self::ucSafe('edit', $username, '', '', $newEmail, 1);
            if ($r === null) return self::err('身份服务暂不可用，请稍后重试');
            if ($r < 0) return self::err('邮箱更新失败：' . self::uc()->errno($r));
        }

        // 手机号唯一性
        if (!empty($upd['mobile'])) {
            $exist = ProfileModel::findByMobile($upd['mobile']);
            if ($exist && intval($exist['uid']) !== intval($uid)) {
                return self::err('手机号已被其他账号绑定');
            }
        }

        $syncStatus = 1; $syncMsg = '已同步 UCenter 核心字段';
        if (!empty($upd)) {
            $upd['updated_at'] = time();
            ProfileModel::save($uid, $upd);
        }
        ProfileModel::markSync($uid, $syncStatus, $syncMsg);

        Logger::info('profile', '修改个人资料', ['uid' => $uid, 'username' => $username, 'extra' => array_keys($upd)]);
        return ['ok' => true];
    }

    /** 聚合用户完整信息（UCenter 为准 + 本地扩展） */
    public static function info($uid)
    {
        $uid  = intval($uid);
        $info = self::ucSafe('getByUid', $uid);
        if (empty($info)) return null;
        list($uUid, $username, $email) = array_pad($info, 3, '');
        $profile = ProfileModel::get($uid) ?: [];

        $last = Database::getInstance()->one(
            "SELECT login_at,login_ip,last_at FROM sessions WHERE uid=? ORDER BY last_at DESC LIMIT 1",
            [$uid]
        );
        $failRow = Database::getInstance()->one("SELECT lock_until FROM login_fail WHERE account=?", [$username]);
        $locked = $failRow && intval($failRow['lock_until']) > time();

        $regEmail = $email;
        $isPlaceholder = strpos($email, '@no-email.uic.local') !== false;

        return [
            'uid'            => $uid,
            'username'       => $username,
            'nickname'       => $profile['nickname'] ?? $username,
            'email'          => $isPlaceholder ? '' : $regEmail,
            'email_bound'    => !$isPlaceholder,
            'mobile'         => $profile['mobile'] ?? '',
            'avatar'         => ProfileModel::avatarUrl($profile, $username),
            'gender'         => intval($profile['gender'] ?? 0),
            'gender_text'    => ['保密', '男', '女'][intval($profile['gender'] ?? 0)] ?? '保密',
            'birthday'       => $profile['birthday'] ?? '',
            'bio'            => $profile['bio'] ?? '',
            'register_time'  => intval($profile['created_at'] ?? 0),
            'register_text'  => human_time($profile['created_at'] ?? 0),
            'last_login_time'=> intval($last['last_at'] ?? 0),
            'last_login_text'=> human_time($last['last_at'] ?? 0),
            'last_login_ip'  => $last['login_ip'] ?? '',
            'status'         => $locked ? 'locked' : 'normal',
            'status_text'    => $locked ? '已锁定' : '正常',
            'source'         => 'UCenter',
            'updated_at'     => intval($profile['updated_at'] ?? 0),
        ];
    }

    /** 账号安全：设备列表 + 登录历史 */
    public static function security($uid)
    {
        $db = Database::getInstance();
        $devices = $db->query(
            "SELECT sid,login_ip,login_ua,device_id,device_label,login_at,last_at,remember, expires_at
             FROM sessions WHERE uid=? AND revoked=0 AND expires_at>? ORDER BY last_at DESC",
            [intval($uid), time()]
        );
        $history = $db->query(
            "SELECT username,target,ip,ua,level,created_at,message FROM logs
             WHERE uid=? AND type IN ('login','logout') ORDER BY id DESC LIMIT 50",
            [intval($uid)]
        );
        foreach ($devices as &$d) {
            $d['is_current'] = UserSession::sid() === $d['sid'];
            $d['login_text'] = human_time($d['login_at']);
            $d['last_text']  = human_time($d['last_at']);
        }
        return ['devices' => $devices, 'history' => $history];
    }

    // ==================== 唯一性检查 ====================

    public static function checkUnique($field, $value)
    {
        if ($field === 'username') {
            Validator::reset();
            if (!Validator::username($value)) return self::err(Validator::first(), -1);
            $r = self::ucSafe('checkName', $value);
            if ($r === null) return self::err('身份服务暂不可用，请稍后重试');
            return [
                'ok' => $r === 1, 'available' => $r === 1, 'code' => $r,
                'message' => [1 => '用户名可用', -1 => '用户名不合法', -2 => '保留字不可注册', -3 => '用户名已被占用'][$r] ?? '未知',
            ];
        }
        if ($field === 'email') {
            Validator::reset();
            if (!Validator::email($value)) return self::err(Validator::first(), -4);
            $r = self::ucSafe('checkEmail', $value);
            if ($r === null) return self::err('身份服务暂不可用，请稍后重试');
            return [
                'ok' => $r === 1, 'available' => $r === 1, 'code' => $r,
                'message' => [1 => '邮箱可用', -4 => '邮箱格式不正确', -5 => '该邮箱不允许注册', -6 => '邮箱已被注册'][$r] ?? '未知',
            ];
        }
        if ($field === 'mobile') {
            Validator::reset();
            if (!Validator::mobile($value)) return self::err(Validator::first(), -4);
            $used = (bool)ProfileModel::findByMobile($value);
            return [
                'ok' => !$used, 'available' => !$used, 'code' => $used ? -6 : 1,
                'message' => $used ? '手机号已被绑定' : '手机号可用',
            ];
        }
        return self::err('不支持的检查字段');
    }

    /** 后台用户管理：优先 UCenter 查询，落到本地 profile */
    public static function adminSearch($kw = '', $page = 1, $size = 20)
    {
        $result = ProfileModel::search($kw, $page, $size);
        $rows = [];
        foreach ($result['rows'] as $p) {
            $info = [];
            try {
                $info = self::ucSafe('getByUid', $p['uid']);
            } catch (Exception $e) {
            }
            $rows[] = [
                'uid'         => intval($p['uid']),
                'username'    => !empty($info) ? $info[1] : ($p['username'] ?: '(UC 未查到)'),
                'email'       => !empty($info) ? $info[2] : '',
                'nickname'    => $p['nickname'],
                'mobile'      => $p['mobile'],
                'gender_text' => ['保密', '男', '女'][intval($p['gender'])] ?? '保密',
                'created_at'  => intval($p['created_at']),
                'updated_at'  => intval($p['updated_at']),
                'sync_status' => intval($p['sync_status']),
                'uc_ok'       => !empty($info),
            ];
        }
        return ['total' => $result['total'], 'rows' => $rows, 'page' => intval($page), 'size' => intval($size)];
    }

    /** 后台：禁用/启用（通过 UCenter 改密码为随机串实现软禁用） */
    public static function adminToggleStatus($uid, $disable)
    {
        $info = self::ucSafe('getByUid', $uid);
        if (empty($info)) return self::err('UCenter 中无此用户');
        $username = $info[1];
        if ($disable) {
            // 记录到日志并标记本地
            ProfileModel::markSync($uid, 2, '管理员已禁用（UCenter 侧密码被随机化）');
            $r = self::ucSafe('edit', $username, '', '!DISABLED_' . rand_str(8), '', 1);
            if ($r === null) return self::err('身份服务暂不可用，请稍后重试');
            if ($r < 0) return self::err('禁用失败：' . self::uc()->errno($r));
            UserSession::destroyAll($uid);
            Logger::warn('admin', '管理员禁用账号', ['uid' => $uid, 'username' => $username]);
        } else {
            ProfileModel::markSync($uid, 0, '待用户通过找回密码重置');
            Logger::info('admin', '管理员恢复账号，等待用户找回密码', ['uid' => $uid, 'username' => $username]);
        }
        return ['ok' => true];
    }

    private static function err($msg, $code = 1)
    {
        return ['ok' => false, 'message' => $msg, 'code' => $code];
    }
}
