<?php
/**
 * UCenter → 应用 通知接收处理器
 *
 * 背景：
 *   UCenter 后台「基本设置 → 通知设置」里的每一个开关，打开后 UCenter 都会
 *   主动向应用推送一条通知，请求形式为：
 *       GET/POST  {应用URL}/api/uc.php?code={urlencode(authcode(query, ENCODE, KEY))}
 *   应用必须返回 '1'（或该动作约定的数据），否则 UCenter 侧显示「通知失败」。
 *
 *   本类覆盖 UCenter 1.5/1.6 全部通知动作，做到：
 *     1. 任何已开通的开关都能被正确接收并回执成功（不再出现「无法过来数据」）
 *     2. 能落地业务的动作真正落地（删用户 / 改名 / 改密强制下线 / 应用列表同步…）
 *     3. 暂无业务含义的动作也完整记录，便于按真实报文继续扩展
 *
 * 与 UCenter 开关的对应关系见 self::ACTIONS 中的 note 字段。
 */
class NotifyHandler
{
    /**
     * action => [名称, UCenter 后台对应开关, 是否需返回数据]
     * 对照 UCenter「基本设置 → 通知设置」页面逐项覆盖。
     */
    const ACTIONS = [
        'test'                 => ['接口测试',           '（应用管理→测试通知）',       0],
        'deleteuser'           => ['用户删除通知',        '是否接受用户删除通知',        0],
        'renameuser'           => ['用户改名通知',        '是否接受用户改名通知',        0],
        'updatepw'             => ['更改用户密码通知',    '是否接受更改用户密码通知',    0],
        'updatebadwords'       => ['更新关键字列表通知',  '是否接受更新关键字列表通知',  0],
        'updatehosts'          => ['更新域名解析缓存',    '是否接受更新域名解析缓存通知', 0],
        'updateapps'           => ['更新应用列表通知',    '是否接受更新应用列表通知',    0],
        'updateclient'         => ['更新客户端缓存',      '是否接受更新客户端缓存通知',  0],
        'updatecache'          => ['更新缓存通知',        '（基础设置→更新缓存）',       0],
        'updatecredit'         => ['更新用户积分通知',    '是否接受更新用户积分通知',    0],
        'getcredit'            => ['获取用户某项积分',    '是否接受获取用户的某项积分通知', 1],
        'getcreditsettings'    => ['提供积分设置',        '是否接受向 UCenter 提供积分设置通知', 1],
        'updatecreditsettings' => ['更新应用积分设置',    '是否接受更新应用积分设置通知', 0],
        'addfeed'              => ['添加事件通知',        '是否接受添加事件通知',        0],
        'gettagg'              => ['获取标签通知',        '是否接受获取标签通知',        0],
        'synlogin'             => ['同步登录通知',        '（用户管理→同步登录）',       0],
        'synlogout'            => ['同步退出通知',        '（用户管理→同步退出）',       0],
    ];

    /** 通知日志表名 */
    const TABLE = 'uc_notices';

    /**
     * 处理一条通知
     *
     * @param array $input 解密后的通知参数（至少含 action）
     * @param array $meta  附加信息：appid / raw / ip / source
     * @return array ['body'=>响应正文, 'status'=>'ok|ignored|rejected|fail', 'message'=>说明]
     */
    public static function handle(array $input, array $meta = [])
    {
        self::ensureTable();

        $t0     = microtime(true);
        $action = strtolower(trim((string)($input['action'] ?? '')));
        $appid  = (string)($meta['appid'] ?? Config::get('uc_appid', ''));

        // ---------- 1. 总开关 ----------
        if (!intval(Config::get('uc_notify_enabled', 1))) {
            return self::finish($action, $input, $meta, 'ignored', '1', '通知接收总开关已关闭', $t0);
        }

        // ---------- 2. action 合法性 ----------
        if ($action === '') {
            return self::finish($action, $input, $meta, 'rejected', 'Invalid Request', '缺少 action 参数', $t0);
        }

        $known = isset(self::ACTIONS[$action]);

        // 未知动作：宽容模式回执成功（避免 UCenter 报通知失败），严格模式拒绝
        if (!$known) {
            if (intval(Config::get('uc_notify_strict', 0))) {
                return self::finish($action, $input, $meta, 'rejected', 'Invalid Request', '未知通知动作（严格模式）', $t0);
            }
            return self::finish($action, $input, $meta, 'ignored', '1', '未知通知动作，已宽容回执（严格模式可在后台开启）', $t0);
        }

        // ---------- 3. 单动作本地开关（只决定是否执行业务，回执始终成功） ----------
        $enabled = self::actionEnabled($action);

        try {
            $body = $enabled
                ? self::dispatch($action, $input, $meta)
                : '1';
            $status = $enabled ? 'ok' : 'ignored';
            $msg    = $enabled ? self::describe($action, $input) : '该通知的本地处理已关闭，仅回执成功';
            return self::finish($action, $input, $meta, $status, (string)$body, $msg, $t0);
        } catch (Exception $e) {
            // 业务异常也不能让 UCenter 收到失败回执——记录后回执成功
            return self::finish($action, $input, $meta, 'fail', '1', '业务处理异常：' . $e->getMessage(), $t0);
        }
    }

    /**
     * 通知接收总入口（HTTP 层只负责输出 body）
     *
     * @param string|null $code 密文；为 null 时自动从当前请求提取
     * @param array       $meta
     * @return array ['body','status','message','action']
     */
    public static function receive($code = null, array $meta = [])
    {
        self::ensureTable();

        $t0  = microtime(true);
        $key = (string)Config::get('uc_key', '');
        if (isset($meta['key']) && $meta['key'] !== '') {
            $key = (string)$meta['key'];
        }
        $appid = (string)($meta['appid'] ?? Config::get('uc_appid', ''));

        if ($code === null) {
            $code = self::extractCode();
        }
        $code = trim((string)$code);

        // ---------- IP 白名单（可选） ----------
        $allow = trim((string)Config::get('uc_notify_ips', ''));
        if ($allow !== '') {
            $ips = array_filter(array_map('trim', preg_split('/[\s,;]+/', $allow)));
            if ($ips && !in_array(Request::ip(), $ips, true)) {
                return self::finish('', [], ['appid' => $appid, 'source' => 'notify'], 'rejected',
                    'Access denied', '来源 IP 不在白名单：' . Request::ip(), $t0);
            }
        }

        if ($code === '') {
            // 无 code：仅当显式开启「明文直传」时才接受，且限无害动作（防止伪造删除/改密通知）
            $plain = self::plainInput();
            if ($plain !== null) {
                return self::handle($plain, ['appid' => $appid, 'source' => 'plain']);
            }
            return self::finish('', [], ['appid' => $appid, 'source' => 'notify'], 'rejected',
                'Invalid Request', '缺少 code 参数', $t0);
        }

        // ---------- 解密（多种编码形态兜底） ----------
        $input = self::decodeCode($code, $key);

        if (empty($input)) {
            $plain = self::plainInput();
            if ($plain !== null) {
                return self::handle($plain, ['appid' => $appid, 'source' => 'plain']);
            }
            return self::finish('', [], ['appid' => $appid, 'source' => 'notify'], 'rejected',
                'Invalid Request', '密文解密失败（常见原因：通信密钥与 UCenter 不一致）', $t0);
        }

        $action = strtolower(trim((string)($input['action'] ?? '')));

        // ---------- 时间戳校验（防重放，可在后台关闭） ----------
        $ttl = intval(Config::get('uc_notify_ttl', 3600));
        if ($ttl > 0 && !empty($input['time'])) {
            $skew = abs(time() - intval($input['time']));
            if ($skew > $ttl) {
                $hint = '通知时间戳偏差 ' . $skew . ' 秒，超出允许范围 ' . $ttl . ' 秒'
                      . '（多为服务器时间不同步，可在后台关闭时间校验）';
                return self::finish($action, $input, ['appid' => $appid, 'source' => 'notify'], 'rejected',
                    'Authracation has expiried', $hint, $t0);
            }
        }

        // L10：密文指纹防重放——同一密文在防重放窗口内不得重复执行（与时间戳校验互补）
        // 窗口默认为 2×时间戳允许偏差；即使时间戳校验被关闭，也保留 60s 最短窗口
        $fpTtl = max(60, $ttl * 2);
        $fp    = hash('sha256', $code);
        $db    = Database::getInstance();
        $db->exec("DELETE FROM uc_notify_fp WHERE created_at<?", [time() - $fpTtl]);
        $dup = $db->one("SELECT fp FROM uc_notify_fp WHERE fp=?", [$fp]);
        if ($dup) {
            return self::finish($action, $input, ['appid' => $appid, 'source' => 'notify'], 'ignored',
                '1', '通知密文已处理过（防重放指纹命中），跳过重复执行', $t0);
        }
        $db->insert('uc_notify_fp', ['fp' => $fp, 'action' => $action, 'created_at' => time()]);

        return self::handle($input, ['appid' => $appid, 'source' => 'notify']);
    }

    /**
     * 从当前请求中提取 code
     * 注意：PHP 会把 query string 中的 '+' 解析为空格，而 authcode 密文里 '+' 很常见，
     *      因此优先从原始 QUERY_STRING 中用 rawurldecode 提取。
     */
    public static function extractCode()
    {
        if (!empty($_SERVER['QUERY_STRING'])) {
            if (preg_match('/(?:^|&)code=([^&]*)/', (string)$_SERVER['QUERY_STRING'], $m)) {
                $c = rawurldecode($m[1]);
                if ($c !== '') {
                    return $c;
                }
            }
        }
        foreach (['code'] as $k) {
            if (isset($_GET[$k]) && $_GET[$k] !== '') {
                return str_replace(' ', '+', (string)$_GET[$k]);
            }
            if (isset($_POST[$k]) && $_POST[$k] !== '') {
                return str_replace(' ', '+', (string)$_POST[$k]);
            }
        }
        return '';
    }

    /**
     * 明文直传（调试用）
     *
     * 默认关闭：明文参数不经过 authcode 签名，任何能访问该 URL 的人都能伪造
     * 「删除用户」「强制改密」等通知，属于高危面。
     * 仅在 uc_notify_plain=1 时启用，且只放行无副作用的白名单动作。
     */
    private static function plainInput()
    {
        if (!intval(Config::get('uc_notify_plain', 0))) {
            return null;
        }
        $in = array_merge($_GET ?: [], $_POST ?: []);
        unset($in['code']);
        $act = strtolower(trim((string)($in['action'] ?? '')));
        if ($act === '' || !in_array($act, self::PLAIN_SAFE, true)) {
            return null;
        }
        $in['action'] = $act;
        $in['_plain'] = 1;
        return $in;
    }

    /** 允许明文直传的动作（无副作用） */
    const PLAIN_SAFE = ['test', 'updatecache', 'updateclient'];

    /** 尝试多种编码形态解密，返回参数数组 */
    private static function decodeCode($code, $key)
    {
        $candidates = [$code];
        // 可能被 urlencode 了两次
        $once = rawurldecode($code);
        if ($once !== $code) {
            $candidates[] = $once;
        }
        $twice = urldecode($code);
        if ($twice !== $code && $twice !== $once) {
            $candidates[] = $twice;
        }
        foreach ($candidates as $c) {
            try {
                $raw = Authcode::decode($c, $key);
            } catch (Exception $e) {
                continue;
            } catch (Throwable $e) {
                continue;
            }
            if ($raw !== '' && $raw !== false) {
                $out = [];
                parse_str($raw, $out);
                if (!empty($out)) {
                    return $out;
                }
            }
        }
        return [];
    }

    /** 动作分发 */
    private static function dispatch($action, array $in, array $meta)
    {
        switch ($action) {
            case 'test':
                return '1';

            // ---------- 用户类 ----------
            case 'deleteuser':
                return self::onDeleteUser($in);
            case 'renameuser':
                return self::onRenameUser($in);
            case 'updatepw':
                return self::onUpdatePw($in);
            case 'synlogout':
                return self::onSynLogout($in);
            case 'synlogin':
                return self::onSynLogin($in);

            // ---------- 应用/缓存类 ----------
            case 'updateapps':
                return self::onUpdateApps($in);
            case 'updateclient':
            case 'updatecache':
                return self::onFlushCache($action, $in);

            // ---------- 规则/缓存文本类 ----------
            case 'updatebadwords':
                return self::onUpdateBadwords($in);
            case 'updatehosts':
                return self::onUpdateHosts($in);

            // ---------- 积分类 ----------
            case 'updatecredit':
                return self::onUpdateCredit($in);
            case 'getcredit':
                return self::onGetCredit($in);
            case 'getcreditsettings':
                return self::onGetCreditSettings();
            case 'updatecreditsettings':
                return self::onUpdateCreditSettings($in);

            // ---------- 事件/标签类 ----------
            case 'addfeed':
                return self::onAddFeed($in);
            case 'gettagg':
                return self::onGetTag($in);

            default:
                return '1';
        }
    }

    // =========================================================
    //  各通知动作的业务实现
    // =========================================================

    /** 用户删除：清本地资料、会话、令牌 */
    private static function onDeleteUser(array $in)
    {
        $uid   = intval($in['uid'] ?? 0);
        $uname = (string)($in['username'] ?? '');
        if ($uid <= 0 && $uname !== '') {
            $row = Database::getInstance()->one("SELECT uid FROM user_profiles WHERE username=?", [$uname]);
            $uid = $row ? intval($row['uid']) : 0;
        }
        if ($uid <= 0) {
            return '1';
        }
        $db = Database::getInstance();
        $db->exec("DELETE FROM user_profiles WHERE uid=?", [$uid]);
        $db->exec("DELETE FROM sessions WHERE uid=?", [$uid]);
        $db->exec("DELETE FROM tokens WHERE uid=?", [$uid]);
        $db->exec("DELETE FROM tickets WHERE uid=?", [$uid]);
        Logger::warn('ucenter', "UCenter 通知：用户已删除 uid={$uid} " . $uname, [
            'uid' => $uid, 'username' => $uname, 'target' => 'deleteuser',
        ]);
        return '1';
    }

    /** 用户改名：同步本地用户名 */
    private static function onRenameUser(array $in)
    {
        $uid  = intval($in['uid'] ?? 0);
        $old  = (string)($in['oldusername'] ?? ($in['username'] ?? ''));
        $new  = (string)($in['newusername'] ?? '');
        if ($uid <= 0 && $old !== '') {
            $row = Database::getInstance()->one("SELECT uid FROM user_profiles WHERE username=?", [$old]);
            $uid = $row ? intval($row['uid']) : 0;
        }
        if ($uid > 0 && $new !== '') {
            $db = Database::getInstance();
            $db->exec("UPDATE user_profiles SET username=?, sync_status=1, sync_msg=?, synced_at=? WHERE uid=?",
                [$new, 'UCenter renameuser 通知', time(), $uid]);
            $db->exec("UPDATE sessions SET username=? WHERE uid=?", [$new, $uid]);
            $db->exec("UPDATE tokens SET username=? WHERE uid=?", [$new, $uid]);
        }
        Logger::info('ucenter', "UCenter 通知：用户改名 {$old} -> {$new}", [
            'uid' => $uid, 'username' => $new, 'target' => 'renameuser',
        ]);
        return '1';
    }

    /**
     * 用户密码变更：应用不存密码，但必须让旧登录态失效（安全要求）
     */
    private static function onUpdatePw(array $in)
    {
        $uid   = intval($in['uid'] ?? 0);
        $uname = (string)($in['username'] ?? '');
        if ($uid <= 0 && $uname !== '') {
            $row = Database::getInstance()->one("SELECT uid FROM user_profiles WHERE username=?", [$uname]);
            $uid = $row ? intval($row['uid']) : 0;
        }
        $kicked = 0;
        if ($uid > 0) {
            $kicked = intval(Database::getInstance()->exec(
                "UPDATE sessions SET revoked=1, revoked_at=? WHERE uid=? AND revoked=0", [time(), $uid]
            )->rowCount());
            Database::getInstance()->exec("DELETE FROM tickets WHERE uid=?", [$uid]);
        }
        Logger::warn('ucenter', "UCenter 通知：用户密码已变更，强制下线 uid={$uid}（{$kicked} 个会话）", [
            'uid' => $uid, 'username' => $uname, 'target' => 'updatepw',
        ]);
        return '1';
    }

    /** 同步退出 */
    private static function onSynLogout(array $in)
    {
        $uid = intval($in['uid'] ?? 0);
        if ($uid > 0) {
            Database::getInstance()->exec("UPDATE sessions SET revoked=1, revoked_at=? WHERE uid=? AND revoked=0",
                [time(), $uid]);
        }
        Logger::info('ucenter', 'UCenter 通知：同步退出 uid=' . $uid, ['uid' => $uid, 'target' => 'synlogout']);
        return '1';
    }

    /**
     * 同步登录：UCenter 告知某用户已在别处登录。
     * 本应用不代替用户建立会话（避免越权），仅记录以便排错。
     */
    private static function onSynLogin(array $in)
    {
        $uid = intval($in['uid'] ?? 0);
        Logger::info('ucenter', 'UCenter 通知：同步登录 uid=' . $uid, ['uid' => $uid, 'target' => 'synlogin']);
        return '1';
    }

    /**
     * 更新应用列表：UCenter 把全站应用配置推给应用
     * 报文结构在不同版本间有差异，这里做宽松解析，能认出的字段就落地。
     */
    private static function onUpdateApps(array $in)
    {
        $apps = [];
        // 形式一：apps 为数组
        if (isset($in['apps']) && is_array($in['apps'])) {
            $apps = $in['apps'];
        } elseif (isset($in['apps']) && is_string($in['apps'])) {
            // 形式二：apps 为序列化串
            $un = @unserialize($in['apps'], ['allowed_classes' => false]);
            if (is_array($un)) {
                $apps = $un;
            }
        }
        // 形式三：app_1_name 之类的扁平键
        if (empty($apps)) {
            foreach ($in as $k => $v) {
                if (preg_match('/^app_?(\d+)_(.+)$/', (string)$k, $m)) {
                    $apps[$m[1]][$m[2]] = $v;
                }
            }
        }

        $synced = 0;
        $db = Database::getInstance();
        foreach ($apps as $key => $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = (string)($item['name'] ?? $item['appname'] ?? '');
            $url  = (string)($item['url'] ?? $item['appurl'] ?? '');
            if ($name === '' && $url === '') {
                continue;
            }
            // 按名称匹配本地应用，仅同步展示用信息，不动密钥
            $row = $db->one("SELECT id FROM apps WHERE name=?", [$name]);
            if ($row) {
                $db->exec("UPDATE apps SET home_url=?, updated_at=? WHERE id=?",
                    [$url, time(), intval($row['id'])]);
                $synced++;
            }
        }
        Database::getInstance()->exec(
            "UPDATE settings SET s_value=?, updated_at=? WHERE s_key='uc_apps_synced_at'", [time(), time()]
        );
        Config::set('uc_apps_synced_at', time(), 'ucenter', '应用列表最近同步时间');

        Logger::info('ucenter', "UCenter 通知：更新应用列表（识别 {$synced} 个本地应用）", [
            'target' => 'updateapps', 'extra' => ['count' => count($apps)],
        ]);
        return '1';
    }

    /** 清缓存：更新客户端缓存 / 更新缓存 */
    private static function onFlushCache($action, array $in)
    {
        // 本应用无分布式缓存，清理配置缓存即可
        try {
            Config::flush();
        } catch (Exception $e) {
        }
        if ($action === 'updateclient') {
            Config::set('uc_client_synced_at', time(), 'ucenter', '客户端缓存最近同步时间');
        }
        Logger::info('ucenter', 'UCenter 通知：' . ($action === 'updateclient' ? '更新客户端缓存' : '更新缓存'),
            ['target' => $action]);
        return '1';
    }

    /** 更新关键字列表（词语过滤） */
    private static function onUpdateBadwords(array $in)
    {
        $raw = $in['badwords'] ?? ($in['data'] ?? '');
        $list = self::normalizeList($raw);
        Config::set('uc_badwords', $list, 'ucenter', 'UCenter 下发的词语过滤列表');
        Config::set('uc_badwords_at', time(), 'ucenter', '词语过滤最近同步时间');
        Logger::info('ucenter', 'UCenter 通知：更新关键字列表（' . count($list) . ' 条）', ['target' => 'updatebadwords']);
        return '1';
    }

    /** 更新域名解析缓存 */
    private static function onUpdateHosts(array $in)
    {
        $raw  = $in['hosts'] ?? ($in['data'] ?? '');
        $list = self::normalizeList($raw);
        Config::set('uc_hosts', $list, 'ucenter', 'UCenter 下发的域名解析缓存');
        Config::set('uc_hosts_at', time(), 'ucenter', '域名解析缓存最近同步时间');
        Logger::info('ucenter', 'UCenter 通知：更新域名解析缓存（' . count($list) . ' 条）', ['target' => 'updatehosts']);
        return '1';
    }

    /** 更新用户积分 */
    private static function onUpdateCredit(array $in)
    {
        $uid = intval($in['uid'] ?? 0);
        if ($uid <= 0 && !empty($in['username'])) {
            $row = Database::getInstance()->one("SELECT uid FROM user_profiles WHERE username=?", [$in['username']]);
            $uid = $row ? intval($row['uid']) : 0;
        }
        $credit = is_array($in['credit'] ?? null) ? json_encode($in['credit'], JSON_UNESCAPED_UNICODE)
            : (string)($in['credit'] ?? ($in['data'] ?? ''));
        $store  = self::creditStore();
        $store  = is_array($store) ? $store : [];
        if ($uid > 0) {
            $store[(string)$uid] = ['credit' => $credit, 'at' => time()];
            Config::set('uc_credits', $store, 'ucenter', 'UCenter 下发的用户积分');
        }
        Logger::info('ucenter', 'UCenter 通知：更新用户积分 uid=' . $uid, [
            'uid' => $uid, 'target' => 'updatecredit', 'extra' => ['credit' => $credit],
        ]);
        return '1';
    }

    /** 获取用户某项积分：UCenter 向应用索取 */
    private static function onGetCredit(array $in)
    {
        $uid    = intval($in['uid'] ?? 0);
        $credit = intval($in['credit'] ?? 0);
        // 本应用不维护积分体系，默认 0（UCenter 期望一个数字）
        $val = 0;
        $store = self::creditStore();
        if (is_array($store) && isset($store[(string)$uid])) {
            $item = $store[(string)$uid]['credit'];
            $arr  = @unserialize($item, ['allowed_classes' => false]);
            if (is_array($arr)) {
                $val = intval($arr[$credit] ?? 0);
            } elseif (is_numeric($item)) {
                $val = intval($item);
            }
        }
        Logger::info('ucenter', "UCenter 通知：索取用户积分 uid={$uid} credit={$credit} -> {$val}", [
            'uid' => $uid, 'target' => 'getcredit',
        ]);
        return (string)$val;
    }

    /**
     * 向 UCenter 提供积分设置
     * UCenter 期望 PHP serialize 数据；本应用无自有积分体系，返回空数组
     */
    private static function onGetCreditSettings()
    {
        $set = Config::get('uc_credit_settings', []);
        if (!is_array($set)) {
            $set = [];
        }
        Logger::info('ucenter', 'UCenter 通知：提供积分设置（' . count($set) . ' 项）', ['target' => 'getcreditsettings']);
        // 按 UCenter 约定返回 serialize 串
        return serialize($set);
    }

    /** 更新应用积分设置 */
    private static function onUpdateCreditSettings(array $in)
    {
        $raw = $in['creditsettings'] ?? ($in['data'] ?? '');
        $set = @unserialize((string)$raw, ['allowed_classes' => false]);
        if (!is_array($set)) {
            $set = self::normalizeList($raw);
        }
        Config::set('uc_credit_settings', $set, 'ucenter', 'UCenter 下发的积分设置');
        Logger::info('ucenter', 'UCenter 通知：更新应用积分设置（' . count($set) . ' 项）', ['target' => 'updatecreditsettings']);
        return '1';
    }

    /** 添加事件（动态/Feed） */
    private static function onAddFeed(array $in)
    {
        $uid   = intval($in['uid'] ?? 0);
        $title = (string)($in['title'] ?? ($in['icon'] ?? ''));
        $body  = (string)($in['body'] ?? ($in['data'] ?? ''));
        Logger::info('ucenter', 'UCenter 通知：添加事件 ' . ($title !== '' ? $title : ''), [
            'uid' => $uid, 'target' => 'addfeed', 'extra' => ['body' => mb_substr($body, 0, 500)],
        ]);
        return '1';
    }

    /** 获取标签：UCenter 索取标签数据 */
    private static function onGetTag(array $in)
    {
        // 本应用无标签体系，返回空 serialize 串（不返回失败，避免 UCenter 报通知失败）
        Logger::info('ucenter', 'UCenter 通知：索取标签数据', ['target' => 'gettagg']);
        return serialize([]);
    }

    // =========================================================
    //  工具方法
    // =========================================================

    /** 通知接收总开关 */
    public static function enabled()
    {
        return (bool)intval(Config::get('uc_notify_enabled', 1));
    }

    /** 单个动作的本地处理开关 */
    public static function actionEnabled($action)
    {
        $map = Config::get('uc_notify_actions', []);
        if (!is_array($map)) {
            $map = json_decode((string)$map, true) ?: [];
        }
        // 未配置过：默认全部开启
        if (!array_key_exists($action, $map)) {
            return true;
        }
        return (bool)intval($map[$action]);
    }

    /** 全部动作的开关快照（供后台渲染） */
    public static function actionMap()
    {
        $map = Config::get('uc_notify_actions', []);
        if (!is_array($map)) {
            $map = json_decode((string)$map, true) ?: [];
        }
        $out = [];
        foreach (self::ACTIONS as $k => $info) {
            $out[$k] = [
                'name'    => $info[0],
                'switch'  => $info[1],
                'return'  => $info[2],
                'enabled' => array_key_exists($k, $map) ? (bool)intval($map[$k]) : true,
            ];
        }
        return $out;
    }

    /** 保留最近 N 条通知日志，防止表膨胀 */
    private static function prune()
    {
        $keep = intval(Config::get('uc_notify_keep', 500));
        if ($keep <= 0) {
            return;
        }
        try {
            // MySQL 不支持 "NOT IN (SELECT ... LIMIT ?)" 子查询，包一层派生表即可
            Database::getInstance()->exec(
                "DELETE FROM " . self::TABLE . " WHERE id NOT IN (SELECT id FROM (SELECT id FROM " . self::TABLE . " ORDER BY id DESC LIMIT ?) t)",
                [$keep]
            );
        } catch (Exception $e) {
        }
    }

    private static function creditStore()
    {
        $s = Config::get('uc_credits', []);
        if (!is_array($s)) {
            $s = json_decode((string)$s, true) ?: [];
        }
        return $s;
    }

    /** 把可能为 serialize / JSON / 分隔串 的值归一化成数组 */
    private static function normalizeList($raw)
    {
        if (is_array($raw)) {
            return $raw;
        }
        $raw = (string)$raw;
        if ($raw === '') {
            return [];
        }
        $un = @unserialize($raw, ['allowed_classes' => false]);
        if (is_array($un)) {
            return $un;
        }
        $json = json_decode($raw, true);
        if (is_array($json)) {
            return $json;
        }
        $lines = preg_split('/[\r\n]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_filter(array_map('trim', $lines), 'strlen'));
    }

    /** 生成一条人类可读的处理说明 */
    private static function describe($action, array $in)
    {
        $name = self::ACTIONS[$action][0] ?? $action;
        $bits = [];
        foreach (['uid', 'username', 'oldusername', 'newusername'] as $k) {
            if (!empty($in[$k])) {
                $bits[] = $k . '=' . $in[$k];
            }
        }
        return $name . ($bits ? '（' . implode(', ', $bits) . '）' : '') . ' 处理完成';
    }

    /** 统一收尾：落库 + 写日志 + 返回响应 */
    private static function finish($action, array $input, array $meta, $status, $body, $message, $t0)
    {
        // 敏感字段脱敏后入库
        $payload = $input;
        foreach (['password', 'newpw', 'oldpw'] as $k) {
            if (isset($payload[$k]) && $payload[$k] !== '') {
                $payload[$k] = '******';
            }
        }
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $json = '(encode failed)';
        }

        try {
            Database::getInstance()->insert(self::TABLE, [
                'action'     => (string)$action,
                'appid'      => (string)($meta['appid'] ?? ''),
                'payload'    => mb_substr($json, 0, 4000),
                'result'     => mb_substr((string)$body, 0, 500),
                'status'     => $status,
                'message'    => mb_substr((string)$message, 0, 500),
                'ip'         => Request::ip(),
                'cost_ms'    => intval(round((microtime(true) - $t0) * 1000)),
                'created_at' => time(),
            ]);
            self::prune();
        } catch (Exception $e) {
            @error_log('[NotifyHandler] ' . $e->getMessage());
        }

        $level = $status === 'ok' ? 'info' : ($status === 'rejected' ? 'error' : 'warn');
        Logger::write('ucenter', 'UCenter 通知[' . ($action !== '' ? $action : 'unknown') . ']：' . $message, [
            'level'  => $level,
            'target' => $action,
            'extra'  => ['status' => $status, 'source' => $meta['source'] ?? 'notify'],
        ]);

        return ['body' => (string)$body, 'status' => $status, 'message' => $message];
    }

    /** 懒建表：保证已部署的老库自动获得通知表，无需手工迁移 */
    public static function ensureTable()
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            $db = Database::getInstance();
            $db->exec("CREATE TABLE IF NOT EXISTS " . self::TABLE . " (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                action      VARCHAR(64) NOT NULL DEFAULT '',
                appid       VARCHAR(64) NOT NULL DEFAULT '',
                payload     TEXT,
                result      VARCHAR(500) NOT NULL DEFAULT '',
                status      VARCHAR(16) NOT NULL DEFAULT 'ok',
                message     VARCHAR(500) NOT NULL DEFAULT '',
                ip          VARCHAR(64) NOT NULL DEFAULT '',
                cost_ms     INT NOT NULL DEFAULT 0,
                created_at  INT NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            // MySQL 5.7 无 CREATE INDEX IF NOT EXISTS：先查 information_schema，避免刷 1061 日志
            $db->exec("CREATE TABLE IF NOT EXISTS uc_notify_fp (
                fp         VARCHAR(64) PRIMARY KEY,
                action     VARCHAR(32) NOT NULL DEFAULT '',
                created_at INT NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            if (!$db->hasIndex(self::TABLE, 'idx_ucnotice_action')) {
                $db->exec("CREATE INDEX idx_ucnotice_action ON " . self::TABLE . "(action)");
            }
            if (!$db->hasIndex(self::TABLE, 'idx_ucnotice_time')) {
                $db->exec("CREATE INDEX idx_ucnotice_time ON " . self::TABLE . "(created_at)");
            }
        } catch (Exception $e) {
            @error_log('[NotifyHandler] ensureTable: ' . $e->getMessage());
        }
    }

    /** 最近通知记录 */
    public static function recent($limit = 30)
    {
        self::ensureTable();
        try {
            return Database::getInstance()->query(
                "SELECT * FROM " . self::TABLE . " ORDER BY id DESC LIMIT " . intval($limit)
            );
        } catch (Exception $e) {
            return [];
        }
    }

    /** 统计 */
    public static function stats()
    {
        self::ensureTable();
        $db = Database::getInstance();
        $total = intval($db->val("SELECT COUNT(*) FROM " . self::TABLE));
        $fail  = intval($db->val("SELECT COUNT(*) FROM " . self::TABLE . " WHERE status IN ('fail','rejected')"));
        $last  = $db->one("SELECT * FROM " . self::TABLE . " ORDER BY id DESC LIMIT 1");
        return ['total' => $total, 'fail' => $fail, 'last' => $last];
    }
}
