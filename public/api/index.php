<?php
/**
 * =============================================================
 *  统一身份认证中心 · Open API 入口
 *  路由格式: api/index.php?m={module}&a={action}
 *  支持 application/x-www-form-urlencoded 与 application/json
 *
 *  企业级能力：RBAC 权限门禁 / TOTP 二步验证 / IP 访问策略 /
 *              会话管理 / 安全审计 / 用户生命周期删除
 * =============================================================
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

// M3：CORS 白名单回显。本 API 依赖 Cookie 会话（credentials），通配符 * 无法携带 Cookie 且
//     会向任意站点放开读取，改为仅在 settings.cors_origins 白名单内回显来源并允许携带凭据。
$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
$corsList = array_values(array_filter(array_map('trim', explode(',', (string)Config::get('cors_origins', '')))));
if ($origin !== '' && in_array($origin, $corsList, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
if (Request::method() === 'OPTIONS') {
    http_response_code(204);
    exit;
}

SsoModel::gc();

$m = preg_replace('/[^a-z_]/', '', strtolower(Request::input('m', 'user')));
$a = preg_replace('/[^a-z_\-]/', '', strtolower(Request::input('a', 'index')));

Logger::write('api', "API 请求 m=$m a=$a", [
    'target' => Request::method() . ' ' . ($_SERVER['REQUEST_URI'] ?? ''),
    'extra'  => ['get' => $_GET, 'post_keys' => array_keys($_POST)],
]);

if (!method_exists('ApiRouter', $m . '_' . $a)) {
    Response::error('接口不存在: ' . $m . '/' . $a, 404, 200);
}

try {
    call_user_func(['ApiRouter', $m . '_' . $a]);
} catch (Throwable $ex) {
    // 数据库异常属服务端内部错误：底层 SQLSTATE / 表结构等细节只写日志，不向用户暴露
    $isDbError = ($ex instanceof PDOException) || (stripos($ex->getMessage(), 'SQLSTATE') !== false);
    Logger::error('api', 'API 异常: ' . $ex->getMessage(), [
        'target' => $m . '/' . $a,
        'extra'  => ['type' => get_class($ex), 'at' => $ex->getFile() . ':' . $ex->getLine()],
    ]);
    if ($isDbError) {
        Response::error('服务暂时不可用，请稍后重试', 500);
    } else {
        Response::error('服务异常：' . $ex->getMessage(), 500);
    }
}

/**
 * 路由处理器
 */
class ApiRouter
{
    // ============ 辅助 ============
    private static function requireLogin()
    {
        $sess = UserSession::current();
        if (!$sess) {
            Response::error('请先登录', 401);
        }
        return $sess;
    }

    /** RBAC 权限门禁：校验当前管理员是否拥有权限点；无权限统一 403 */
    private static function requirePerm($perm)
    {
        $admin = AdminModel::current();
        if (!$admin) {
            Response::error('请先登录后台', 401);
        }
        if (!Rbac::can($admin, $perm)) {
            Logger::warn('admin', '权限不足被拒：' . $perm, [
                'admin_id' => intval($admin['id']),
                'username' => $admin['username'],
                'extra'    => ['perm' => $perm],
            ]);
            Response::error('权限不足，无法执行该操作', 403, 200);
        }
        return $admin;
    }

    /** 登录 IP 访问策略（企业风控：白名单/黑名单，默认关闭） */
    private static function requireIpAllowed($ctx = 'login')
    {
        list($ok, $msg) = IpPolicy::check();
        if (!$ok) {
            Logger::warn($ctx, 'IP 策略拒绝访问：' . $msg, ['target' => Request::ip()]);
            Response::error($msg, 403, 200);
        }
    }

    private static function result($r, $okMsg = '操作成功')
    {
        if (is_array($r) && array_key_exists('ok', $r) && !$r['ok']) {
            Response::error($r['message'] ?? '操作失败', isset($r['code']) ? intval($r['code']) : 1, 200, $r['data'] ?? null);
        }
        Response::success($r, $okMsg);
    }

    /** 后台开启图形验证码时，强制校验登录/找回密码请求携带的 captcha_token + captcha_answer */
    private static function requireCaptchaIfEnabled()
    {
        if (!Config::get('login_captcha')) {
            return;
        }
        // 无感信任凭证：已通过无感验证的浏览器（HMAC 签名、绑定 IP）直接放行，实现跨页无感
        if (class_exists('RiskEngine') && RiskEngine::trustValid()) {
            return;
        }
        list($ok, $msg) = CaptchaModel::verify(
            Request::input('captcha_token'),
            Request::input('captcha_answer')
        );
        if (!$ok) {
            // 无感验证评分未通过：转换为用户可读的提示（前端收到后会自动刷新为交互式验证码）
            if ($msg === '__passive_fail__') {
                $msg = '安全验证未通过，请刷新后重新验证';
            } elseif ($msg === '__passive_block__') {
                // 风险评分触发直接拦截：明确告知已被拦截（区别于普通「请刷新」）
                $msg = '安全验证未通过，本次访问已被拦截，请稍后重试';
            }
            Response::error($msg, 406, 200);
        }
    }

    // ---------- 企业级：管理员 TOTP / 密码策略辅助 ----------

    /** 管理员 TOTP 是否已启用 */
    private static function adminTotpEnabled($adminId)
    {
        return intval(Config::get('admin_totp_enabled_' . intval($adminId), 0)) === 1;
    }

    /** 管理员密码是否超期（admin_pwd_expire_days>0 且距上次改密超期） */
    private static function adminPwdExpired($adminId)
    {
        $days = intval(Config::get('admin_pwd_expire_days', 0));
        if ($days <= 0) {
            return false;
        }
        $last = intval(Config::get('admin_pwd_changed_at_' . intval($adminId), 0));
        if ($last <= 0) {
            return false; // 存量管理员无改密记录视为宽限期，不强制
        }
        return (time() - $last) > $days * 86400;
    }

    // ======================================================
    //  一、用户接口  m=user
    // ======================================================

    /** 注册 */
    public static function user_register()
    {
        $in = [
            'username' => Request::input('username'),
            'password' => Request::input('password'),
            'confirm'  => Request::input('confirm', Request::input('password')),
            'email'    => Request::input('email', ''),
            'mobile'   => Request::input('mobile', ''),
            'nickname' => Request::input('nickname', ''),
            'gender'   => Request::input('gender', 0),
        ];
        $r = UserService::register($in, Request::input('auto_login', '1') == '1');
        self::result($r, '注册成功');
    }

    /** 登录 */
    public static function user_login()
    {
        self::requireIpAllowed('login');
        self::requireCaptchaIfEnabled();
        $r = UserService::login(
            Request::input('account', Request::input('username')),
            Request::input('password'),
            Request::input('remember', '0') == '1',
            Request::input('app_id', '')
        );
        self::result($r, '登录成功');
    }

    /** 验证码登录 - 发送验证码 */
    public static function user_send_login_code()
    {
        self::requireIpAllowed('login');
        self::requireCaptchaIfEnabled();
        self::result(UserService::sendLoginCode(Request::input('target')), '验证码已发送');
    }

    /** 验证码登录 - 校验并登录 */
    public static function user_login_code()
    {
        self::requireIpAllowed('login');
        $r = UserService::loginByCode(
            Request::input('target'),
            Request::input('code'),
            Request::input('remember', '0') == '1',
            Request::input('app_id', '')
        );
        self::result($r, '登录成功');
    }

    /** 退出当前设备 */
    public static function user_logout()
    {
        self::result(UserService::logout(Request::input('sid', '')), '已退出登录');
    }

    /** 退出全部设备 */
    public static function user_logoutall()
    {
        $sess = UserSession::current();
        $r = UserService::logoutAll($sess ? intval($sess['uid']) : 0);
        self::result($r, '已退出全部设备');
    }

    /** 当前登录用户信息 */
    public static function user_info()
    {
        $sess = self::requireLogin();
        $info = UserService::info(intval($sess['uid']));
        if (!$info) {
            Response::error('账号在 UCenter 中已不存在', 404);
        }
        Response::success($info);
    }

    /** 账号安全：设备 + 历史 */
    public static function user_security()
    {
        $sess = self::requireLogin();
        Response::success(UserService::security(intval($sess['uid'])));
    }

    /** 踢掉指定设备 */
    public static function user_device_kill()
    {
        $sess = self::requireLogin();
        $target = Request::input('sid', '');
        if (!$target) {
            Response::error('缺少 sid');
        }
        $row = Database::getInstance()->one("SELECT * FROM sessions WHERE sid=?", [$target]);
        if (!$row || intval($row['uid']) !== intval($sess['uid'])) {
            Response::error('设备不存在');
        }
        UserSession::destroy($target);
        Logger::info('logout', '用户退出指定设备', ['uid' => intval($sess['uid']), 'target' => substr($target, 0, 12)]);
        Response::success(null, '已退出该设备');
    }

    /** 修改密码 */
    public static function user_password()
    {
        $sess = self::requireLogin();
        $r = UserService::changePassword(
            intval($sess['uid']),
            Request::input('old_password'),
            Request::input('new_password'),
            Request::input('confirm')
        );
        self::result($r, '密码修改成功，请重新登录');
    }

    /** 修改资料 */
    public static function user_profile()
    {
        $sess = self::requireLogin();
        $data = [
            'nickname' => Request::input('nickname'),
            'gender'   => Request::input('gender'),
            'birthday' => Request::input('birthday'),
            'bio'      => Request::input('bio'),
            'avatar'   => Request::input('avatar'),
            'email'    => Request::input('email'),
            'mobile'   => Request::input('mobile'),
        ];
        $data = array_filter($data, function ($v) { return $v !== null && $v !== ''; });
        self::result(UserService::updateProfile(intval($sess['uid']), $data), '资料已更新');
    }

    /** 找回密码 - 发送验证码 */
    public static function user_findpwd_send()
    {
        self::requireIpAllowed('findpwd');
        self::requireCaptchaIfEnabled();
        self::result(UserService::sendResetCode(Request::input('target')), '验证码已发送');
    }

    /** 找回密码 - 重置 */
    public static function user_findpwd_reset()
    {
        $r = UserService::resetPassword(
            Request::input('target'),
            Request::input('code'),
            Request::input('new_password'),
            Request::input('confirm')
        );
        self::result($r, '密码已重置，请使用新密码登录');
    }

    /** 唯一性检查 */
    public static function user_check()
    {
        $field = Request::input('field', 'username');
        $value = Request::input('value', '');
        if ($value === '') {
            Response::error('缺少待检查的值');
        }
        Response::success(UserService::checkUnique($field, $value));
    }

    // ======================================================
    //  二、SSO 接口  m=sso
    // ======================================================

    /** 应用跳转授权：已登录直接 302 回回调并带 ticket，否则返回 need_login */
    public static function sso_authorize()
    {
        $appId    = Request::input('app_id');
        $callback = Request::input('redirect', Request::input('callback'));
        $r = SsoService::authorize($appId, $callback, []);
        if ($r['ok']) {
            Response::redirect($r['redirect']);
        }
        if (isset($r['need_login'])) {
            Response::error('用户未登录', 401, 200, [
                'login_url' => site_url('index.php?c=user&a=login&app_id=' . urlencode($appId) . '&redirect=' . urlencode($callback)),
                'app'       => $r['app'],
                'callback'  => $r['callback'],
            ]);
        }
        Response::error($r['message']);
    }

    /** ticket 换取 token（服务端对接） */
    public static function sso_exchange()
    {
        $r = SsoService::exchange(
            Request::input('ticket'),
            Request::input('app_id'),
            Request::input('app_secret')
        );
        self::result($r, '换取成功');
    }

    /** token 换用户信息 */
    public static function sso_userinfo()
    {
        $token = Request::input('token') ?: Request::bearer();
        self::result(SsoService::userinfo($token, Request::input('app_id', '')));
    }

    /** 单点退出 */
    public static function sso_logout()
    {
        $token = Request::input('token') ?: Request::bearer();
        self::result(SsoService::logout($token, Request::input('app_id', '')), '已单点退出');
    }

    /** 应用通信测试 */
    public static function sso_test()
    {
        self::result(SsoService::testConnect(Request::input('app_id'), Request::input('app_secret')), '通信正常');
    }

    // ======================================================
    //  三、管理接口  m=admin（RBAC 权限门禁）
    // ======================================================
    private static function requireAdmin()
    {
        $admin = AdminModel::current();
        if (!$admin) {
            Response::error('请先登录后台', 401);
        }
        return $admin;
    }

    /** 登录（图形验证码 + IP 策略 + TOTP 二步验证 + 密码有效期） */
    public static function admin_login()
    {
        // 后台登录网关：开启时强制校验图形验证码（与前台登录同款验证码体系，独立开关）
        if (Config::get('admin_login_captcha', true)) {
            // 无感信任凭证：已通过无感验证的浏览器直接放行
            if (!(class_exists('RiskEngine') && RiskEngine::trustValid())) {
                list($ok, $msg) = CaptchaModel::verify(
                    Request::input('captcha_token'),
                    Request::input('captcha_answer')
                );
                if (!$ok) {
                    if ($msg === '__passive_fail__') {
                        $msg = '安全验证未通过，请刷新后重新验证';
                    } elseif ($msg === '__passive_block__') {
                        $msg = '安全验证未通过，本次访问已被拦截，请稍后重试';
                    }
                    Response::error($msg, 406, 200);
                }
            }
        }
        // 企业风控：登录 IP 黑白名单（默认关闭）
        self::requireIpAllowed('admin');

        $r = AdminModel::login(Request::input('username'), Request::input('password'));
        list($ok, $msg, $row) = $r;
        if (!$ok) {
            Response::error($msg, 401);
        }

        // ---- TOTP 二步验证（登录第二段）----
        if (self::adminTotpEnabled($row['id'])) {
            $totpCode = (string)Request::input('totp_code');
            if ($totpCode === '') {
                // 第一段：账号密码已通过，提示前端进入第二步
                Response::success([
                    'need_totp' => true,
                    'id'        => intval($row['id']),
                    'username'  => $row['username'],
                ], '请输入动态验证码完成登录');
            }
            $secret = (string)Config::get('admin_totp_secret_' . $row['id'], '');
            if ($secret === '' || !Totp::verify($secret, $totpCode)) {
                Logger::warn('admin', '管理员 TOTP 校验失败', [
                    'admin_id' => intval($row['id']),
                    'username' => $row['username'],
                ]);
                Response::error('动态验证码不正确或已过期', 401);
            }
            // 防重放：同一时间步进不可重复使用
            $tc = Totp::timecode();
            if (intval(Config::get('admin_totp_last_tc_' . $row['id'], 0)) >= $tc) {
                Response::error('该验证码已使用，请等待刷新后重试', 401);
            }
            Config::set('admin_totp_last_tc_' . $row['id'], $tc, 'admin_totp');
        }

        AdminModel::authorize($row);
        Logger::info('admin', '管理员登录成功', ['admin_id' => intval($row['id']), 'username' => $row['username']]);
        // 企业策略：带出密码超期标志（超期则引导去改密页；首登强制改密已移除）
        Response::success([
            'id'              => intval($row['id']),
            'username'        => $row['username'],
            'role'            => $row['role'],
            'need_pwd_change' => self::adminPwdExpired($row['id']) ? 1 : 0,
        ], '登录成功');
    }

    /** 管理员修改自己的密码（记录改密时间供密码有效期策略） */
    public static function admin_change_pwd()
    {
        $admin = self::requireAdmin();
        $old = (string)Request::input('old_password');
        $new = (string)Request::input('new_password');
        // 管理员口令不弱于 8 位（站内普通用户策略可更低）
        $minLen = max(8, intval(Config::get('security.pwd_min_length', 8)));
        $maxLen = intval(Config::get('security.pwd_max_length', 32));
        if ($new === '' || strlen($new) < $minLen || strlen($new) > $maxLen) {
            Response::error("新密码长度需为 {$minLen}-{$maxLen} 位");
        }
        if (!preg_match('/[A-Za-z]/', $new) || !preg_match('/[0-9]/', $new)) {
            Response::error('新密码必须同时包含字母和数字');
        }
        if ($new === $old) {
            Response::error('新密码不能与原密码相同');
        }
        // 企业策略：历史密码防重用（保留最近 5 次）
        $history = (array)json_decode((string)Config::get('admin_pwd_history_' . $admin['id'], '[]'), true);
        foreach (array_slice($history, 0, 5) as $h) {
            if (password_verify($new, (string)$h)) {
                Response::error('新密码与最近使用过的密码重复，请更换');
            }
        }
        list($ok, $msg) = AdminModel::changePassword($admin['id'], $old, $new);
        if (!$ok) {
            Response::error($msg, 401);
        }
        $history = array_merge([password_hash($new, PASSWORD_DEFAULT)], array_slice($history, 0, 4));
        Config::set('admin_pwd_history_' . $admin['id'], $history, 'admin_pwd', '历史密码（防重用）');
        Config::set('admin_pwd_changed_at_' . $admin['id'], time(), 'admin_pwd', '最近改密时间（密码有效期策略）');
        Logger::info('admin', '管理员修改密码(id=' . $admin['id'] . ')', ['admin_id' => intval($admin['id'])]);
        Response::success(null, '密码已修改，请牢记新密码');
    }

    /**
     * 后台「开放接口」的人机验证：复用后台登录验证码开关
     * 无感验证已签发信任凭证的浏览器直接放行（HMAC 签名、绑定 IP）
     */
    private static function requireAdminCaptchaIfEnabled()
    {
        if (!Config::get('admin_login_captcha', true)) {
            return;
        }
        if (class_exists('RiskEngine') && RiskEngine::trustValid()) {
            return;
        }
        list($ok, $msg) = CaptchaModel::verify(
            Request::input('captcha_token'),
            Request::input('captcha_answer')
        );
        if (!$ok) {
            if ($msg === '__passive_fail__') {
                $msg = '安全验证未通过，请刷新后重新验证';
            } elseif ($msg === '__passive_block__') {
                $msg = '安全验证未通过，本次访问已被拦截，请稍后重试';
            }
            Response::error($msg, 406, 200);
        }
    }

    /** 管理员找回密码 - 第一步：发送邮箱验证码（公开接口，靠图形验证码 + IP 策略把关） */
    public static function admin_findpwd_send()
    {
        self::requireAdminCaptchaIfEnabled();
        self::requireIpAllowed('admin');
        self::result(AdminResetService::sendCode(
            Request::input('username'),
            Request::input('email')
        ), '验证码已发送');
    }

    /** 管理员找回密码 - 第二步：校验验证码并重置密码 */
    public static function admin_findpwd_reset()
    {
        self::requireIpAllowed('admin');
        self::result(AdminResetService::reset(
            Request::input('username'),
            Request::input('email'),
            Request::input('code'),
            Request::input('new_password'),
            Request::input('confirm')
        ), '密码已重置，请使用新密码登录');
    }

    public static function admin_logout()
    {
        $a = AdminModel::current();
        if ($a) Logger::info('admin', '管理员退出', ['admin_id' => intval($a['id']), 'username' => $a['username']]);
        AdminModel::logout();
        Response::success(null, '已退出');
    }

    /** 控制台统计（所有已登录管理员可看） */
    public static function admin_stats()
    {
        self::requireAdmin();
        $db = Database::getInstance();
        $uc = new UCenterClient();
        $ping = $uc->ping();
        Response::success([
            'sso'    => SsoModel::stats(),
            'users'  => [
                'local_profiles' => intval($db->val("SELECT COUNT(*) FROM user_profiles")),
                'today_register' => intval($db->val("SELECT COUNT(*) FROM logs WHERE type='register' AND created_at>" . strtotime('today'))),
                'today_login'    => intval($db->val("SELECT COUNT(*) FROM logs WHERE type='login' AND created_at>" . strtotime('today'))),
            ],
            'health' => [
                'ucenter' => $ping['ok'] ? '正常' : '异常',
                'ucenter_msg' => $ping['message'],
                'api'     => '正常',
                'db'      => '正常',
            ],
            'security' => [
                // 企业级：今日登录失败 / 当前锁定账号 / 管理员数
                'fail_today' => intval($db->val("SELECT COUNT(*) FROM logs WHERE level='warn' AND type='login' AND created_at>" . strtotime('today'))),
                'locked_now' => intval($db->val("SELECT COUNT(*) FROM login_fail WHERE lock_until>" . time())),
                'admins'     => intval($db->val("SELECT COUNT(*) FROM admins WHERE status=1")),
            ],
        ]);
    }

    /** 用户管理：列表（RBAC view:users） */
    public static function admin_user_list()
    {
        self::requirePerm('view:users');
        Response::success(UserService::adminSearch(
            Request::input('kw', ''),
            intval(Request::input('page', 1)),
            intval(Request::input('size', 20))
        ));
    }

    /** 用户管理：禁用/启用/踢出（RBAC manage:users） */
    public static function admin_user_action()
    {
        $admin = self::requirePerm('manage:users');
        $uid   = intval(Request::input('uid'));
        $act   = Request::input('act');
        if ($act === 'disable') {
            $r = UserService::adminToggleStatus($uid, true);
        } elseif ($act === 'enable') {
            $r = UserService::adminToggleStatus($uid, false);
        } elseif ($act === 'kick') {
            UserSession::destroyAll($uid);
            SsoModel::revokeUser($uid);
            $r = ['ok' => true];
        } else {
            Response::error('不支持的操作');
        }
        Logger::info('admin', '管理员操作用户 uid=' . $uid . ' act=' . $act, ['admin_id' => intval($admin['id']), 'uid' => $uid]);
        self::result($r, '操作成功');
    }

    /**
     * 用户管理：永久删除（账号生命周期闭环）
     * 流程：UCenter 注销 -> 清理本地会话/令牌/票据/资料/验证码/限流记录
     */
    public static function admin_user_delete()
    {
        $admin = self::requirePerm('manage:users');
        $uid   = intval(Request::input('uid'));
        if ($uid <= 0) {
            Response::error('缺少 uid');
        }
        $info = UserService::uc()->getByUid($uid);
        if (empty($info)) {
            Response::error('UCenter 中无此用户');
        }
        $username = (string)$info[1];

        // 1) 同步注销 UCenter 核心身份（Mock/标准 UC 均支持 user.delete）
        $r = UserService::uc()->delete($uid);
        if ($r === -7) {
            Response::error('UCenter 服务端未实现删除接口(deleteuser)，请先在 UCenter 侧删除该用户', 501, 200);
        }
        if ($r <= 0) {
            Response::error('UCenter 删除失败（返回 ' . $r . '），操作已中止');
        }

        // 2) 清理本地全部关联数据
        $db = Database::getInstance();
        foreach (['sessions', 'tokens', 'tickets', 'user_profiles'] as $tb) {
            $db->exec("DELETE FROM $tb WHERE uid=?", [$uid]);
        }
        $db->delete('login_fail', 'account=?', [$username]);

        Logger::warn('admin', '永久删除用户（含 UCenter 注销）', [
            'admin_id' => intval($admin['id']),
            'username' => $username,
            'uid'      => $uid,
            'extra'    => ['email' => $info[2] ?? ''],
        ]);
        Response::success(null, '用户已永久删除（UCenter 与本地数据均已清理）');
    }

    /** 应用管理：列表（RBAC view:apps） */
    public static function admin_app_list()
    {
        self::requirePerm('view:apps');
        Response::success(AppModel::all());
    }

    /** 应用管理：保存（RBAC manage:apps） */
    public static function admin_app_save()
    {
        $admin = self::requirePerm('manage:apps');
        $id    = intval(Request::input('id', 0));
        $callbacks = Request::input('callbacks', []);
        if (is_string($callbacks)) {
            $callbacks = array_filter(array_map('trim', preg_split('/[\r\n,]+/', $callbacks)));
        }
        $data = [
            'name'        => Request::input('name'),
            'description' => Request::input('description', ''),
            'home_url'    => Request::input('home_url', ''),
            'callbacks'   => (array)$callbacks,
            'status'      => Request::input('status', 1),
            'sso_enabled' => Request::input('sso_enabled', 1),
        ];
        if (!$data['name']) {
            Response::error('应用名称不能为空');
        }
        if ($id) {
            AppModel::update($id, $data);
        } else {
            $id = AppModel::create(array_merge($data, [
                'app_id' => Request::input('app_id') ?: AppModel::genAppId(),
            ]));
        }
        Logger::info('app', ($id ? '编辑' : '新增') . '应用 #' . $id, ['admin_id' => intval($admin['id']), 'target' => $data['name']]);
        Response::success(['id' => $id], '保存成功');
    }

    public static function admin_app_reset_secret()
    {
        $admin = self::requirePerm('manage:apps');
        $id = intval(Request::input('id'));
        $s  = AppModel::resetSecret($id);
        Logger::warn('app', '重置应用 AppSecret #' . $id, ['admin_id' => intval($admin['id'])]);
        Response::success(['app_secret' => $s], 'AppSecret 已重置');
    }

    public static function admin_app_toggle()
    {
        $admin = self::requirePerm('manage:apps');
        $id = intval(Request::input('id'));
        $st = intval(Request::input('status', 0));
        AppModel::toggle($id, $st);
        Logger::info('app', ($st ? '启用' : '禁用') . '应用 #' . $id, ['admin_id' => intval($admin['id'])]);
        Response::success(null, '状态已更新');
    }

    public static function admin_app_delete()
    {
        $admin = self::requirePerm('manage:apps');
        $id = intval(Request::input('id'));
        AppModel::remove($id);
        Logger::warn('app', '删除应用 #' . $id, ['admin_id' => intval($admin['id'])]);
        Response::success(null, '已删除');
    }

    public static function admin_app_test()
    {
        self::requirePerm('view:apps');
        $id = intval(Request::input('id'));
        $app = AppModel::findById($id);
        if (!$app) Response::error('应用不存在');
        self::result(SsoService::testConnect($app['app_id'], $app['app_secret']), '通信测试完成');
    }

    /** UCenter 配置（RBAC manage:ucenter，含密钥，仅限超管/授权角色） */
    public static function admin_uc_config()
    {
        self::requirePerm('manage:ucenter');
        $uc = new UCenterClient();
        Response::success(['config' => $uc->getConfig(), 'health' => $uc->ping()]);
    }

    public static function admin_uc_save()
    {
        $admin = self::requirePerm('manage:ucenter');
        $fields = ['uc_enabled', 'uc_driver', 'uc_url', 'uc_ip', 'uc_appid', 'uc_timeout', 'uc_ssl_verify', 'uc_ca_file'];
        foreach ($fields as $f) {
            $v = Request::input($f, null);
            if ($v !== null) {
                Config::set($f, $v, 'ucenter');
            }
        }
        // 通信密钥留空表示不修改
        $key = Request::input('uc_key', '');
        if ($key !== '') {
            Config::set('uc_key', $key, 'ucenter');
        }
        Logger::info('admin', '保存 UCenter 配置', ['admin_id' => intval($admin['id'])]);
        Response::success(null, '配置已保存');
    }

    public static function admin_uc_test()
    {
        self::requirePerm('manage:ucenter');
        $uc = new UCenterClient();
        Response::success($uc->healthCheck(), 'UCenter 体检完成');
    }

    public static function admin_uc_call()
    {
        self::requirePerm('manage:ucenter');
        $method = Request::input('method', 'ping');
        $uc = new UCenterClient();
        $map = [
            'ping'     => fn() => $uc->ping(),
            'login'    => fn() => $uc->login(Request::input('account'), Request::input('password')),
            'register' => fn() => $uc->register(Request::input('username'), Request::input('password'), Request::input('email')),
            'getuser'  => fn() => $uc->getUser(Request::input('account')),
            'checkname'=> fn() => $uc->checkName(Request::input('username')),
            'checkemail'=>fn() => $uc->checkEmail(Request::input('email')),
            'editpwd'  => fn() => $uc->edit(Request::input('username'), Request::input('old_password'), Request::input('new_password'), '', Request::input('ignore_old') ? 1 : 0),
            'editprofile' => fn() => $uc->edit(Request::input('username'), '', '', Request::input('email'), 1),
        ];
        if (!isset($map[$method])) {
            Response::error('不支持的测试项');
        }
        try {
            $r = $map[$method]();
            if ($method === 'ping') {
                Response::success($r, '连接测试完成');
            }
            $msg = (is_array($r) ? '返回数组' : '返回 ' . $r . ' ' . ($uc->errno(is_numeric($r) ? intval($r) : -999)));
            Response::success(['raw' => $r, 'explain' => $msg, 'errno_msg' => is_scalar($r) ? $uc->errno(intval($r)) : ''], '调用完成');
        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    /** UCenter 通知接收配置（RBAC manage:ucenter） */
    public static function admin_uc_notify()
    {
        self::requirePerm('manage:ucenter');
        NotifyHandler::ensureTable();
        Response::success([
            'enabled'   => intval(Config::get('uc_notify_enabled', 1)),
            'ttl'       => intval(Config::get('uc_notify_ttl', 3600)),
            'strict'    => intval(Config::get('uc_notify_strict', 0)),
            'ips'       => (string)Config::get('uc_notify_ips', ''),
            'keep'      => intval(Config::get('uc_notify_keep', 500)),
            'plain'     => intval(Config::get('uc_notify_plain', 0)),
            'callback'  => rtrim(site_url(''), '/') . '/api/uc.php',
            'apifile'   => 'api/uc.php',
            'actions'   => NotifyHandler::actionMap(),
            'stats'     => NotifyHandler::stats(),
            'notices'   => NotifyHandler::recent(40),
            'server_time' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function admin_uc_notify_save()
    {
        $admin = self::requirePerm('manage:ucenter');
        foreach (['uc_notify_enabled' => 'int', 'uc_notify_ttl' => 'int',
                  'uc_notify_strict' => 'int', 'uc_notify_keep' => 'int'] as $k => $t) {
            $v = Request::input($k, null);
            if ($v !== null) {
                Config::set($k, intval($v), 'ucenter');
            }
        }
        $plain = Request::input('uc_notify_plain', null);
        if ($plain !== null) {
            Config::set('uc_notify_plain', intval($plain) ? 1 : 0, 'ucenter');
        }
        $ips = Request::input('uc_notify_ips', null);
        if ($ips !== null) {
            Config::set('uc_notify_ips', trim((string)$ips), 'ucenter');
        }
        // 动作开关：以 JSON 串整体提交
        $actions = Request::input('uc_notify_actions', null);
        if ($actions !== null && $actions !== '') {
            $map = json_decode((string)$actions, true);
            if (!is_array($map)) {
                Response::error('动作开关格式错误');
            }
            $clean = [];
            foreach ($map as $k => $v) {
                $k = preg_replace('/[^a-z_]/', '', strtolower((string)$k));
                if ($k === '' || !isset(NotifyHandler::ACTIONS[$k])) {
                    continue;
                }
                $clean[$k] = intval($v) ? 1 : 0;
            }
            Config::set('uc_notify_actions', $clean, 'ucenter', '各通知动作本地处理开关');
        }
        Logger::info('admin', '保存 UCenter 通知接收配置', ['admin_id' => intval($admin['id'])]);
        Response::success(null, '通知配置已保存');
    }

    /**
     * 通知接收闭环自测
     * 用与 UCenter 完全一致的方式（authcode 加密 code 参数）向本站 api/uc.php
     * 发一条真实通知，验证「网络可达 + 密钥一致 + 解密正确 + 回执成功」。
     */
    public static function admin_uc_notify_test()
    {
        $admin  = self::requirePerm('manage:ucenter');
        $action = preg_replace('/[^a-z_]/', '', strtolower((string)Request::input('action', 'test')));
        if (!isset(NotifyHandler::ACTIONS[$action])) {
            Response::error('不支持测试该通知动作');
        }
        $key   = (string)Config::get('uc_key', '');
        $appid = (string)Config::get('uc_appid', '');

        $data = ['action' => $action, 'time' => time(), 'appid' => $appid];
        // 部分动作补上示例参数，使自测更贴近真实报文
        if (in_array($action, ['renameuser'], true)) {
            $data += ['uid' => 0, 'oldusername' => 'selftest_old', 'newusername' => 'selftest_new'];
        } elseif (in_array($action, ['deleteuser', 'updatepw', 'synlogout', 'synlogin', 'updatecredit', 'getcredit', 'addfeed'], true)) {
            $data += ['uid' => 0, 'username' => 'selftest'];
        }
        if ($action === 'getcredit') {
            $data['credit'] = 1;
        }

        $cipher = Authcode::encode(http_build_query($data), $key);
        $url    = rtrim(site_url(''), '/') . '/api/uc.php?code=' . urlencode($cipher);

        // ---------- 方式一：真实 HTTP 回环 ----------
        $http = ['ok' => false, 'code' => '', 'body' => '', 'cost_ms' => 0, 'error' => ''];
        $t = microtime(true);
        $body = null;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_SSL_VERIFYPEER => (bool)Config::get('uc_ssl_verify', true),
                CURLOPT_SSL_VERIFYHOST => (bool)Config::get('uc_ssl_verify', true) ? 2 : 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT      => 'UCenter/1.6 Notify Selftest',
            ]);
            $body = curl_exec($ch);
            $http['code'] = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
            if ($body === false) {
                $http['error'] = curl_error($ch);
            }
            curl_close($ch);
        }
        $http['cost_ms'] = intval(round((microtime(true) - $t) * 1000));
        $http['body'] = is_string($body) ? trim($body) : '';
        $http['ok'] = ($http['code'] === 200 && $http['body'] !== '' && strpos($http['body'], 'Invalid') === false
            && strpos($http['body'], 'expiried') === false);

        // ---------- 方式二：进程内直调（HTTP 回环不可用时兜底） ----------
        $inner = NotifyHandler::receive($cipher);

        Logger::info('admin', 'UCenter 通知接收自测：' . $action, [
            'admin_id' => intval($admin['id']),
            'extra'    => ['http_ok' => $http['ok'], 'http_code' => $http['code'], 'inner' => $inner['status']],
        ]);

        Response::success([
            'action'    => $action,
            'callback'  => $url,
            'payload'   => $data,
            'http'      => $http,
            'inner'     => $inner,
            'pass'      => ($http['ok'] || $inner['status'] === 'ok'),
        ], '通知接收自测完成');
    }

    /** 清空通知记录 */
    public static function admin_uc_notify_clear()
    {
        $admin = self::requirePerm('manage:ucenter');
        NotifyHandler::ensureTable();
        $n = Database::getInstance()->exec("DELETE FROM uc_notices")->rowCount();
        Logger::info('admin', '清空 UCenter 通知记录', ['admin_id' => intval($admin['id']), 'extra' => ['rows' => $n]]);
        Response::success(['rows' => $n], '已清空 ' . $n . ' 条通知记录');
    }

    /** 日志（RBAC view:logs） */
    public static function admin_log_list()
    {
        self::requirePerm('view:logs');
        Response::success(Logger::search([
            'type'   => Request::input('type', ''),
            'level'  => Request::input('level', ''),
            'uid'    => Request::input('uid', ''),
            'app_id' => Request::input('app_id', ''),
            'kw'     => Request::input('kw', ''),
        ], intval(Request::input('page', 1)), intval(Request::input('size', 20))));
    }

    /** 日志清理（RBAC manage:logs，默认仅超管） */
    public static function admin_log_clear()
    {
        $admin = self::requirePerm('manage:logs');
        $days = intval(Request::input('days', 90));
        $n = Logger::purge($days);
        Logger::info('admin', '清理 ' . $days . ' 天前日志，共 ' . $n . ' 条', ['admin_id' => intval($admin['id'])]);
        Response::success(['deleted' => $n], '已清理');
    }

    public static function admin_uc_calls()
    {
        self::requirePerm('view:logs');
        $rows = Database::getInstance()->query("SELECT * FROM uc_calls ORDER BY id DESC LIMIT 50");
        Response::success($rows);
    }

    /** 系统设置（RBAC manage:system） */
    public static function admin_settings()
    {
        self::requirePerm('manage:system');
        $group = Request::input('group', '');
        $rows  = $group ? Config::group($group) : Database::getInstance()->query("SELECT * FROM settings ORDER BY s_group, s_key");
        Response::success($rows);
    }

    public static function admin_settings_save()
    {
        $admin = self::requirePerm('manage:system');
        $data = Request::input('data', []);
        if (is_string($data)) {
            $data = json_decode($data, true) ?: [];
        }
        if (!is_array($data)) {
            Response::error('参数格式错误');
        }
        // 统一按大纲（SettingsSchema）做类型归一化与取值校验：
        // 布尔→0/1、数字→数值、枚举→白名单、JSON→结构校验，未收录的键原样保存。
        $clean  = [];
        $errors = [];
        foreach ($data as $k => $v) {
            $k = (string)$k;
            list($ok, $nv, $msg) = SettingsSchema::normalize($k, $v);
            if (!$ok) {
                $errors[] = $msg;
                continue;
            }
            $clean[$k] = $nv;
        }
        if ($errors) {
            Response::error('配置校验未通过：' . implode('；', array_slice($errors, 0, 3)));
        }
        // 跨字段业务规则：IP 访问策略（策略 + 名单需联合校验）
        if (array_key_exists('login_ip_policy', $clean) || array_key_exists('login_ip_list', $clean)) {
            list($ok, $msg) = IpPolicy::validatePolicy(
                $clean['login_ip_policy'] ?? intval(Config::get('login_ip_policy', 0)),
                $clean['login_ip_list'] ?? (string)Config::get('login_ip_list', '')
            );
            if (!$ok) {
                Response::error('IP 访问策略配置有误：' . $msg);
            }
        }
        // 验证码样式：normalize 已按枚举校验，这里给出更明确的语义提示
        if (array_key_exists('captcha_style', $clean)
            && !array_key_exists((string)$clean['captcha_style'], CaptchaModel::styles())) {
            Response::error('默认验证方式配置有误：' . $clean['captcha_style']);
        }
        if (array_key_exists('captcha_fallback', $clean)
            && !array_key_exists((string)$clean['captcha_fallback'], CaptchaModel::fallbackStyles())) {
            Response::error('无感验证回退方式配置有误：' . $clean['captcha_fallback']);
        }
        // 通行密钥：启用时检查 HTTPS（WebAuthn 仅在安全上下文可用，127.0.0.1/localhost 除外）
        $pkWarn = '';
        if (array_key_exists('passkey_enabled', $clean) && intval($clean['passkey_enabled']) === 1) {
            $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (($_SERVER['SERVER_PORT'] ?? 80) == 443);
            $host  = strtolower($_SERVER['HTTP_HOST'] ?? '');
            $local = $host === '127.0.0.1' || $host === 'localhost' || strpos($host, '127.0.0.1:') === 0;
            if (!$https && !$local) {
                $pkWarn = '注意：当前站点非 HTTPS，通行密钥在浏览器中无法使用（WebAuthn 要求安全上下文）。请先配置 HTTPS 证书。';
            }
        }

        foreach ($clean as $k => $v) {
            // 分组按大纲落库（模块 id 即 s_group），保证「设置中心」分类稳定
            $def    = SettingsSchema::def($k);
            $remark = is_array($def) && isset($def['label']) ? (string)$def['label'] : null;
            Config::set($k, $v, SettingsSchema::moduleOf($k), $remark);
        }
        Logger::info('admin', '保存系统设置', ['admin_id' => intval($admin['id']), 'extra' => array_keys($clean)]);
        Response::success(['warn' => $pkWarn], $pkWarn ?: '设置已保存');
    }

    /** 站点信息保存（RBAC manage:system）：备案号 / 联系方式 / 底部信息 */
    public static function admin_site_save()
    {
        $admin = self::requirePerm('manage:system');
        $fields = [
            'site_name', 'site_slogan', 'site_url', 'site_logo', 'site_company',
            'site_icp', 'site_icp_link', 'site_police', 'site_police_link',
            'site_contact_email', 'site_contact_phone', 'site_contact_url',
            'site_copyright', 'site_footer_text', 'site_footer_html',
        ];
        foreach ($fields as $f) {
            $v = Request::input($f, null);
            if ($v !== null) {
                Config::set($f, $v, 'site');
            }
        }
        Logger::info('admin', '保存站点信息', ['admin_id' => intval($admin['id']), 'extra' => $fields]);
        Response::success(null, '站点信息已保存');
    }

    /** 管理员管理（RBAC manage:admins） */
    public static function admin_admin_list()
    {
        self::requirePerm('manage:admins');
        Response::success(AdminModel::all());
    }

    public static function admin_admin_save()
    {
        $admin = self::requirePerm('manage:admins');
        $id = intval(Request::input('id', 0));
        if ($id) {
            $role = Request::input('role', 'admin');
            if ($id === 1 && $role !== 'super_admin') {
                Response::error('系统内置管理员角色不可变更，操作已拒绝');
            }
            if (!isset(Rbac::roles()[$role])) {
                Response::error('不支持的权限角色');
            }
            $email = trim((string)Request::input('email', ''));
            if ($email !== '') {
                Validator::reset();
                if (!Validator::email($email)) {
                    Response::error('邮箱格式不正确');
                }
                Validator::reset();
            }
            Database::getInstance()->update('admins', [
                'realname'   => Request::input('realname', ''),
                'email'      => $email,
                'role'       => $role,
                'updated_at' => time(),
            ], 'id=?', [$id]);
            Logger::info('admin', '编辑管理员 #' . $id, ['admin_id' => intval($admin['id'])]);
            Response::success(['id' => $id], '已保存');
        }
        $role = Request::input('role', 'admin');
        if (!isset(Rbac::roles()[$role])) {
            Response::error('不支持的权限角色');
        }
        $r = AdminModel::create(
            Request::input('username'),
            Request::input('password'),
            Request::input('realname', ''),
            $role,
            Request::input('email', '')
        );
        if (!$r[0]) {
            Response::error($r[1]);
        }
        Logger::info('admin', '新增管理员 ' . Request::input('username'), ['admin_id' => intval($admin['id'])]);
        Response::success(['id' => $r[2]], '已创建');
    }

    public static function admin_admin_password()
    {
        $admin = self::requireAdmin();
        $r = AdminModel::changePassword(
            intval($admin['id']),
            Request::input('old_password'),
            Request::input('new_password')
        );
        if (!$r[0]) {
            Response::error($r[1]);
        }
        Response::success(null, '密码已修改');
    }

    public static function admin_admin_toggle()
    {
        $admin = self::requirePerm('manage:admins');
        $ok = AdminModel::toggle(intval(Request::input('id')), intval(Request::input('status', 0)));
        if (!$ok) {
            Response::error('系统内置管理员不可禁用，操作已拒绝');
        }
        Response::success(null, '已更新');
    }

    public static function admin_admin_delete()
    {
        $admin = self::requirePerm('manage:admins');
        $ok = AdminModel::remove(intval(Request::input('id')));
        if (!$ok) {
            Response::error('系统内置管理员不可删除，操作已拒绝');
        }
        Response::success(null, '已删除');
    }

    // ======================================================
    //  四、企业级：会话管理  m=admin（RBAC sessions）
    // ======================================================

    /** 在线会话总览（分页 + 关键字筛选） */
    public static function admin_session_list()
    {
        self::requirePerm('view:sessions');
        $db   = Database::getInstance();
        $kw   = trim((string)Request::input('kw', ''));
        $page = max(1, intval(Request::input('page', 1)));
        $size = min(50, max(10, intval(Request::input('size', 20))));

        $where = 's.revoked=0 AND s.expires_at>' . time();
        $args  = [];
        if ($kw !== '') {
            // 搜索同时覆盖 profiles 与 sessions.username（未建档用户也能被搜到）
            $where .= ' AND (p.username LIKE ? OR p.nickname LIKE ? OR s.username LIKE ? OR s.login_ip LIKE ? OR s.login_ua LIKE ? OR s.device_label LIKE ?)';
            $like  = '%' . $kw . '%';
            $args  = [$like, $like, $like, $like, $like, $like];
        }
        $total = intval($db->val("SELECT COUNT(*) FROM sessions s LEFT JOIN user_profiles p ON p.uid=s.uid WHERE $where", $args));
        $off   = ($page - 1) * $size;
        // 用户名回退链：profiles.username -> profiles.nickname -> sessions.username（登录时必写，未建档用户不再显示为空）
        $rows  = $db->query(
            "SELECT s.sid, s.uid, s.device_label, s.device_id, s.login_ip, s.login_ua,
                    s.login_at, s.last_at, s.remember, s.expires_at, s.username AS s_username,
                    COALESCE(NULLIF(p.username, ''), s.username) AS username,
                    COALESCE(NULLIF(p.nickname, ''), NULLIF(p.username, ''), s.username) AS nickname
             FROM sessions s LEFT JOIN user_profiles p ON p.uid=s.uid
             WHERE $where ORDER BY s.last_at DESC LIMIT $size OFFSET $off",
            $args
        );
        $now = time();
        foreach ($rows as &$r) {
            $r['login_text']   = human_time($r['login_at']);
            $r['last_text']    = human_time($r['last_at']);
            $r['expires_text'] = ($r['expires_at'] > $now) ? human_time($r['expires_at']) : '已过期';
            $r['is_current']   = UserSession::sid() === $r['sid'];
            $r['ua_short']     = mb_substr(preg_replace('/\s+/', ' ', (string)$r['login_ua']), 0, 64);
            $r['remember']     = intval($r['remember']);
        }
        Response::success(['total' => $total, 'rows' => $rows, 'page' => $page, 'size' => $size]);
    }

    /** 强制下线指定会话 */
    public static function admin_session_kill()
    {
        $admin = self::requirePerm('manage:sessions');
        $sid   = (string)Request::input('sid');
        if ($sid === '') {
            Response::error('缺少 sid');
        }
        if (UserSession::sid() === $sid) {
            Response::error('该会话为当前登录会话（管理员会话），请勿误操作');
        }
        $row = Database::getInstance()->one("SELECT uid FROM sessions WHERE sid=?", [$sid]);
        if (!$row) {
            Response::error('会话不存在或已下线');
        }
        UserSession::destroy($sid);
        Logger::warn('admin', '强制下线用户会话', [
            'admin_id' => intval($admin['id']),
            'uid'      => intval($row['uid']),
            'extra'    => ['sid' => substr($sid, 0, 12)],
        ]);
        Response::success(null, '已强制下线该会话');
    }

    /** 强制下线某用户全部会话 */
    public static function admin_session_kick_user()
    {
        $admin = self::requirePerm('manage:sessions');
        $uid   = intval(Request::input('uid'));
        if ($uid <= 0) {
            Response::error('缺少 uid');
        }
        $n = UserSession::destroyAll($uid);
        SsoModel::revokeUser($uid);
        Logger::warn('admin', '强制下线用户全部会话', [
            'admin_id' => intval($admin['id']),
            'uid'      => $uid,
            'extra'    => ['count' => $n],
        ]);
        Response::success(['revoked' => $n], '已强制下线该用户全部 ' . $n . ' 个会话');
    }

    // ======================================================
    //  五、企业级：管理员 TOTP 二步验证  m=admin
    // ======================================================

    /** TOTP 状态查询（本人操作，无需额外权限） */
    public static function admin_totp_status()
    {
        $admin = self::requireAdmin();
        Response::success([
            'enabled'    => self::adminTotpEnabled($admin['id']) ? 1 : 0,
            'has_secret' => (string)Config::get('admin_totp_secret_' . $admin['id'], '') !== '' ? 1 : 0,
        ]);
    }

    /** 生成/重置 TOTP Secret（绑定第一步；重置会先关闭启用态） */
    public static function admin_totp_setup()
    {
        $admin  = self::requireAdmin();
        $secret = Totp::generateSecret();
        Config::set('admin_totp_secret_' . $admin['id'], $secret, 'admin_totp', 'TOTP 绑定密钥（请勿外泄）');
        Config::set('admin_totp_enabled_' . $admin['id'], 0, 'admin_totp');
        Response::success([
            'secret' => $secret,
            'uri'    => Totp::uri($secret, $admin['username'] . '@uic'),
        ], '请将密钥录入认证器后，输入动态验证码完成绑定');
    }

    /** 验证当前动态码并启用 TOTP */
    public static function admin_totp_enable()
    {
        $admin  = self::requireAdmin();
        $secret = (string)Config::get('admin_totp_secret_' . $admin['id'], '');
        if ($secret === '') {
            Response::error('请先生成绑定密钥');
        }
        $code = (string)Request::input('code');
        if (!Totp::verify($secret, $code)) {
            Response::error('动态验证码不正确或已过期', 406, 200);
        }
        Config::set('admin_totp_enabled_' . $admin['id'], 1, 'admin_totp');
        Config::set('admin_totp_last_tc_' . $admin['id'], Totp::timecode(), 'admin_totp');
        Logger::info('admin', '管理员启用 TOTP 二步验证', ['admin_id' => intval($admin['id'])]);
        Response::success(null, '两步验证已启用，下次登录需输入动态验证码');
    }

    /** 关闭 TOTP（需提交当前动态码验证持有者身份） */
    public static function admin_totp_disable()
    {
        $admin  = self::requireAdmin();
        $secret = (string)Config::get('admin_totp_secret_' . $admin['id'], '');
        if ($secret === '') {
            Response::error('尚未启用两步验证');
        }
        $code = (string)Request::input('code');
        if (!Totp::verify($secret, $code)) {
            Response::error('动态验证码不正确或已过期', 406, 200);
        }
        Config::set('admin_totp_enabled_' . $admin['id'], 0, 'admin_totp');
        Config::remove('admin_totp_last_tc_' . $admin['id']);
        Logger::warn('admin', '管理员关闭 TOTP 二步验证', ['admin_id' => intval($admin['id'])]);
        Response::success(null, '两步验证已关闭');
    }

    // ======================================================
    //  六、企业级：安全审计中心  m=admin
    // ======================================================

    /** 安全审计聚合统计（风险概览 + 告警趋势 + 最新告警明细） */
    public static function admin_audit_stats()
    {
        self::requirePerm('view:audit');
        $db    = Database::getInstance();
        $today = strtotime('today');

        $summary = [
            'fail_today'  => intval($db->val("SELECT COUNT(*) FROM logs WHERE level='warn' AND created_at>" . $today)),
            'locked_now'  => intval($db->val("SELECT COUNT(*) FROM login_fail WHERE lock_until>" . time())),
            'uc_fail'     => intval($db->val("SELECT COUNT(*) FROM uc_calls WHERE status='fail' AND created_at>" . $today)),
            'admin_ops'   => intval($db->val("SELECT COUNT(*) FROM logs WHERE type='admin' AND created_at>" . $today)),
            'total_logs'  => intval($db->val("SELECT COUNT(*) FROM logs")),
            'error_level' => intval($db->val("SELECT COUNT(*) FROM logs WHERE level='error'")),
            'today_login' => intval($db->val("SELECT COUNT(*) FROM logs WHERE type='login' AND level='info' AND created_at>" . $today)),
        ];

        // 今日告警按类型分布
        $warnTypes = $db->query(
            "SELECT type, COUNT(*) c FROM logs WHERE level='warn' AND created_at>? GROUP BY type ORDER BY c DESC",
            [$today]
        );

        // 近 7 天登录/失败趋势
        $trend = [];
        for ($i = 6; $i >= 0; $i--) {
            $d0 = $today - $i * 86400;
            $d1 = $d0 + 86400;
            $trend[] = [
                'date'  => date('m-d', $d0),
                'login' => intval($db->val("SELECT COUNT(*) FROM logs WHERE type='login' AND level='info' AND created_at>=? AND created_at<?", [$d0, $d1])),
                'fail'  => intval($db->val("SELECT COUNT(*) FROM logs WHERE level='warn' AND type IN ('login','admin') AND created_at>=? AND created_at<?", [$d0, $d1])),
            ];
        }

        // 最新告警明细（warn/error 级）
        $recent = $db->query("SELECT * FROM logs WHERE level IN ('warn','error') ORDER BY id DESC LIMIT 40");
        foreach ($recent as &$r) {
            $r['extra'] = json_decode($r['extra'] ?: '[]', true);
        }

        Response::success([
            'summary'    => $summary,
            'warn_types' => $warnTypes,
            'trend'      => $trend,
            'recent'     => $recent,
        ]);
    }

    // ======================================================
    //  七、图形验证码接口  m=captcha（人机验证，无登录态要求）
    // ======================================================

    /** 生成图形验证码：style 可选 slide/click/icon/passive/digits/mixed，缺省按后台 captcha_style 配置 */
    public static function captcha_issue()
    {
        try {
            $d = CaptchaModel::issue(Request::input('style', ''));
            Response::success($d);
        } catch (Exception $e) {
            Response::error('图形验证码生成失败：' . $e->getMessage());
        }
    }

    /**
     * 校验图形验证码（前端即时校验/无感验证预检，不消耗 token；
     * 登录等最终校验由请求中间件再次调用并消耗）
     */
    public static function captcha_verify()
    {
        list($ok, $msg) = CaptchaModel::verify(
            Request::input('token'),
            Request::input('answer'),
            false
        );
        if (!$ok) {
            Response::error($msg, 406, 200);
        }
        Response::success(true, '验证通过');
    }

    // ======================================================
    //  八、第三方登录 / 账号注销
    // ======================================================

    /** 已登录用户解除第三方绑定 */
    public static function user_oauth_unbind()
    {
        $sess = self::requireLogin();
        $provider = preg_replace('/[^a-z_]/', '', strtolower((string)Request::input('provider', '')));
        if (!$provider || !OAuth::get($provider)) {
            Response::error('参数错误');
        }
        OAuthService::unbind(intval($sess['uid']), $provider);
        Response::success(null, '已解除绑定');
    }

    /** 提交账号注销申请（前端开放平台） */
    public static function user_deletion_request()
    {
        $sess = self::requireLogin();
        list($ok, $msg) = DeletionService::request(intval($sess['uid']), (string)Request::input('reason', ''));
        if (!$ok) {
            Response::error($msg, 400, 200);
        }
        Response::success(null, $msg);
    }

    /** 保存单个第三方平台配置（RBAC manage:system） */
    public static function admin_oauth_save()
    {
        $admin = self::requirePerm('manage:system');
        $provider = preg_replace('/[^a-z_]/', '', strtolower((string)Request::input('provider', '')));
        if (!$provider || !OAuth::get($provider)) {
            Response::error('不支持的第三方登录平台');
        }
        $cfg = [
            'enabled'     => intval(Request::input('enabled', 0)) ? 1 : 0,
            'appid'       => trim((string)Request::input('appid', '')),
            'secret'      => trim((string)Request::input('secret', '')),
            'private_key' => trim((string)Request::input('private_key', '')),
        ];
        Config::set('oauth_' . $provider, json_encode($cfg, JSON_UNESCAPED_UNICODE), 'oauth');
        Logger::info('admin', '保存第三方登录配置', [
            'admin_id' => intval($admin['id']),
            'extra'    => ['provider' => $provider, 'enabled' => $cfg['enabled']],
        ]);
        Response::success(null, '配置已保存');
    }

    /** 保存第三方登录全局策略 */
    public static function admin_oauth_save_global()
    {
        $admin = self::requirePerm('manage:system');
        $auto = intval(Request::input('auto_register', 0)) ? 1 : 0;
        Config::set('oauth_auto_register', $auto, 'oauth');
        Logger::info('admin', '保存第三方登录全局策略', [
            'admin_id' => intval($admin['id']),
            'extra'    => ['auto_register' => $auto],
        ]);
        Response::success(null, '策略已保存');
    }

    /** 注销申请列表（RBAC view:users） */
    public static function admin_deletion_list()
    {
        self::requirePerm('view:users');
        Response::success(DeletionService::listAll(
            (string)Request::input('status', 'pending'),
            intval(Request::input('page', 1)),
            20
        ));
    }

    /** 注销审核：通过 / 驳回（RBAC view:users） */
    public static function admin_deletion_review()
    {
        $admin = self::requirePerm('view:users');
        $id      = intval(Request::input('id', 0));
        $approve = intval(Request::input('approve', 0)) === 1;
        $remark  = (string)Request::input('remark', '');
        if ($id <= 0) {
            Response::error('参数错误');
        }
        list($ok, $msg) = DeletionService::review($id, $approve, (string)($admin['username'] ?? 'admin'), $remark);
        if (!$ok) {
            Response::error($msg, 400, 200);
        }
        Response::success(null, $msg);
    }

    // ======================================================
    //  七、SDK 压缩包管理  m=sdkpkg（RBAC manage:system）
    // ======================================================

    /** SDK 包列表 */
    public static function sdkpkg_list()
    {
        self::requirePerm('manage:system');
        Response::success(['packages' => SdkPackageService::listPackages()]);
    }

    /** SDK 包上传（multipart/form-data, 字段 file/title/version/desc） */
    public static function sdkpkg_upload()
    {
        $admin = self::requirePerm('manage:system');
        $r = SdkPackageService::upload(
            $_FILES['file'] ?? [],
            trim((string)Request::input('title', '')),
            trim((string)Request::input('version', '')),
            trim((string)Request::input('desc', ''))
        );
        if (!$r['ok']) {
            Response::error($r['message'], 400, 200);
        }
        Response::success(['name' => $r['name']], '上传成功，前端下载中心已同步');
    }

    /** SDK 包删除（内置包不可删） */
    public static function sdkpkg_delete()
    {
        $admin = self::requirePerm('manage:system');
        $name  = (string)Request::input('name', '');
        $r = SdkPackageService::remove($name, intval($admin['id']));
        if (!$r['ok']) {
            Response::error($r['message'], 400, 200);
        }
        Response::success(null, '已删除');
    }

    // ======================================================
    //  九、通行密钥 / WebAuthn  m=passkey
    // ======================================================

    /** 通行密钥功能状态（公开接口，前端探测可用性） */
    public static function passkey_status()
    {
        $issue = PasskeyService::domainIssue();
        Response::success([
            'enabled'       => PasskeyService::enabled() ? 1 : 0,
            'rp_id'         => PasskeyService::rpId(),
            // 当前访问域是否可用于 WebAuthn（IP 访问 / 非 https / 域不匹配时为 false）
            'domain_ok'     => $issue ? 0 : 1,
            'domain_issue'  => $issue ? $issue['code'] : '',
            'domain_reason' => $issue ? $issue['message'] : '',
            'domain_hint'   => $issue ? $issue['hint'] : '',
        ]);
    }

    /** 注册选项（需登录，安全中心添加新通行密钥） */
    public static function passkey_register_options()
    {
        $sess = self::requireLogin();
        $r = PasskeyService::registerOptions(intval($sess['uid']));
        self::result($r, '请完成设备验证');
    }

    /** 完成注册（需登录） */
    public static function passkey_register_finish()
    {
        $sess = self::requireLogin();
        $r = PasskeyService::registerFinish(
            intval($sess['uid']),
            Request::input('tx', ''),
            Request::input('client_data', ''),
            Request::input('attestation', ''),
            Request::input('label', '')
        );
        self::result($r, '通行密钥已添加');
    }

    /** 认证选项（公开接口，登录页调用） */
    public static function passkey_auth_options()
    {
        $sess = UserSession::current();
        $uid  = $sess ? intval($sess['uid']) : 0;
        $r = PasskeyService::authOptions($uid);
        self::result($r, '请选择通行密钥');
    }

    /** 完成认证（公开接口，创建会话） */
    public static function passkey_auth_finish()
    {
        self::requireIpAllowed('login');
        $r = PasskeyService::authFinish(
            Request::input('tx', ''),
            Request::input('credential_id', ''),
            Request::input('client_data', ''),
            Request::input('authenticator_data', ''),
            Request::input('signature', ''),
            Request::input('remember', '0') == '1',
            Request::input('app_id', '')
        );
        self::result($r, '登录成功');
    }

    /** 列出当前用户的通行密钥（需登录） */
    public static function passkey_list()
    {
        $sess = self::requireLogin();
        Response::success(['items' => PasskeyService::list(intval($sess['uid']))]);
    }

    /** 重命名通行密钥（需登录） */
    public static function passkey_rename()
    {
        $sess = self::requireLogin();
        $r = PasskeyService::rename(
            intval($sess['uid']),
            intval(Request::input('id', 0)),
            Request::input('label', '')
        );
        self::result($r, '已重命名');
    }

    /** 删除通行密钥（需登录） */
    public static function passkey_delete()
    {
        $sess = self::requireLogin();
        $r = PasskeyService::delete(
            intval($sess['uid']),
            intval(Request::input('id', 0))
        );
        self::result($r, '已删除');
    }

    // ======================================================
    //  九、会员中心产品展示管理  m=admin_product（RBAC manage:system）
    // ======================================================

    /** 产品列表（后台） */
    public static function admin_product_list()
    {
        self::requirePerm('manage:system');
        Response::success(['items' => MemberProductModel::all()]);
    }

    /** 新增产品 */
    public static function admin_product_create()
    {
        self::requirePerm('manage:system');
        $name = (string)Request::input('name', '');
        if ($name === '') {
            Response::error('产品名称不能为空');
        }
        $newId = MemberProductModel::create([
            'name'        => $name,
            'url'         => (string)Request::input('url', ''),
            'description' => (string)Request::input('description', ''),
            'icon'        => (string)Request::input('icon', 'link'),
            'sort_order'  => intval(Request::input('sort_order', 0)),
            'enabled'     => intval(Request::input('enabled', 1)) === 1 ? 1 : 0,
        ]);
        Response::success(['id' => $newId], '已新增');
    }

    /** 编辑产品 */
    public static function admin_product_update()
    {
        self::requirePerm('manage:system');
        $id = intval(Request::input('id', 0));
        if ($id <= 0) {
            Response::error('参数错误');
        }
        $name = (string)Request::input('name', '');
        if ($name === '') {
            Response::error('产品名称不能为空');
        }
        MemberProductModel::update($id, [
            'name'        => $name,
            'url'         => (string)Request::input('url', ''),
            'description' => (string)Request::input('description', ''),
            'icon'        => (string)Request::input('icon', 'link'),
            'sort_order'  => intval(Request::input('sort_order', 0)),
            'enabled'     => intval(Request::input('enabled', 1)) === 1 ? 1 : 0,
        ]);
        Response::success(['id' => $id], '已保存');
    }

    /** 删除产品 */
    public static function admin_product_delete()
    {
        self::requirePerm('manage:system');
        $id = intval(Request::input('id', 0));
        if ($id <= 0) {
            Response::error('参数错误');
        }
        MemberProductModel::delete($id);
        Response::success(null, '已删除');
    }

    /** 启用/禁用产品 */
    public static function admin_product_toggle()
    {
        self::requirePerm('manage:system');
        $id      = intval(Request::input('id', 0));
        $enabled = intval(Request::input('enabled', 1)) === 1 ? 1 : 0;
        if ($id <= 0) {
            Response::error('参数错误');
        }
        MemberProductModel::update($id, ['enabled' => $enabled]);
        Response::success(null, $enabled ? '已启用' : '已禁用');
    }
}