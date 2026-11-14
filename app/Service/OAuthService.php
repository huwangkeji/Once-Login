<?php
/**
 * 第三方登录业务服务
 * 职责：绑定关系维护、自动创建并绑定系统账号、建立登录会话
 * 与 OAuth（协议层，app/Core/OAuth.php）分工：协议归协议，业务归业务
 */
class OAuthService
{
    /** 建表（幂等，兼容已部署库升级补齐） */
    public static function ensureTables()
    {
        Database::getInstance()->exec(
            "CREATE TABLE IF NOT EXISTS user_oauth (
                id            INT AUTO_INCREMENT PRIMARY KEY,
                uid           INT NOT NULL DEFAULT 0,
                provider      VARCHAR(32)  NOT NULL,
                openid        VARCHAR(128) NOT NULL,
                unionid       VARCHAR(128) NOT NULL DEFAULT '',
                nickname      VARCHAR(64)  NOT NULL DEFAULT '',
                avatar        VARCHAR(255) NOT NULL DEFAULT '',
                access_token  VARCHAR(512) NOT NULL DEFAULT '',
                refresh_token VARCHAR(512) NOT NULL DEFAULT '',
                expires_at    INT NOT NULL DEFAULT 0,
                raw_data      TEXT,
                created_at    INT NOT NULL DEFAULT 0,
                updated_at    INT NOT NULL DEFAULT 0,
                UNIQUE KEY uk_provider_openid (provider, openid),
                KEY idx_uid (uid),
                KEY idx_unionid (unionid)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public static function findBinding($provider, $openid)
    {
        self::ensureTables();
        return Database::getInstance()->one(
            "SELECT * FROM user_oauth WHERE provider=? AND openid=? LIMIT 1",
            [$provider, $openid]
        );
    }

    /** 某用户已绑定的第三方账号 */
    public static function bindings($uid)
    {
        self::ensureTables();
        return Database::getInstance()->query(
            "SELECT * FROM user_oauth WHERE uid=? ORDER BY id ASC",
            [intval($uid)]
        );
    }

    /** 写入/更新绑定（MySQL 5.7 兼容：先 UPDATE 再 INSERT） */
    public static function bind($uid, $provider, array $info)
    {
        self::ensureTables();
        $db  = Database::getInstance();
        $now = time();
        $raw = json_encode($info['raw'] ?? [], JSON_UNESCAPED_UNICODE);
        $st  = $db->prepare(
            "UPDATE user_oauth SET uid=?, nickname=?, avatar=?, access_token=?, refresh_token=?,
             expires_at=?, raw_data=?, updated_at=? WHERE provider=? AND openid=?"
        );
        $st->execute([
            intval($uid),
            (string)($info['nickname'] ?? ''),
            (string)($info['avatar'] ?? ''),
            (string)($info['token'] ?? ''),
            (string)($info['refresh'] ?? ''),
            $now + intval($info['expires'] ?? 0),
            $raw, $now,
            $provider, (string)$info['openid'],
        ]);
        if ($st->rowCount() === 0) {
            $db->prepare(
                "INSERT IGNORE INTO user_oauth
                 (uid,provider,openid,unionid,nickname,avatar,access_token,refresh_token,expires_at,raw_data,created_at,updated_at)
                 VALUES(?,?,?,?,?,?,?,?,?,?,?,?)"
            )->execute([
                intval($uid), $provider, (string)$info['openid'],
                (string)($info['unionid'] ?? ''),
                (string)($info['nickname'] ?? ''),
                (string)($info['avatar'] ?? ''),
                (string)($info['token'] ?? ''),
                (string)($info['refresh'] ?? ''),
                $now + intval($info['expires'] ?? 0),
                $raw, $now, $now,
            ]);
        }
        return true;
    }

    public static function unbind($uid, $provider)
    {
        self::ensureTables();
        Database::getInstance()->delete('user_oauth', 'uid=? AND provider=?', [intval($uid), $provider]);
        Logger::info('oauth', '解除第三方绑定', ['uid' => intval($uid), 'extra' => ['provider' => $provider]]);
        return true;
    }

    /**
     * 处理第三方回调
     * @return array ['ok'=>bool,'message'=>string,'uid'=>int,'username'=>string,'is_new'=>bool,'provider'=>string]
     */
    public static function handleCallback($provider, $code, $bindUid = 0)
    {
        if (!OAuth::isEnabled($provider)) {
            return self::fail('该第三方登录方式未启用或未配置');
        }
        try {
            $token = OAuth::accessToken($provider, $code);
            $info  = OAuth::userInfo($provider, $token);
        } catch (Exception $e) {
            Logger::error('oauth', '第三方登录失败：' . $e->getMessage(), ['extra' => ['provider' => $provider]]);
            return self::fail($e->getMessage());
        }

        $isNew = false;
        $bind  = self::findBinding($provider, $info['openid']);

        if ($bindUid > 0) {
            // 已登录用户主动绑定：若该第三方号已绑到其它账号则拒绝
            if ($bind && intval($bind['uid']) > 0 && intval($bind['uid']) !== intval($bindUid)) {
                return self::fail('该第三方账号已绑定其它本站账号，请先解绑');
            }
            $uid = intval($bindUid);
        } elseif ($bind && intval($bind['uid']) > 0) {
            $uid = intval($bind['uid']);
        } else {
            if (!intval(Config::get('oauth_auto_register', 1))) {
                return self::fail('该第三方账号未绑定本站账号，且未开启自动注册');
            }
            $uid = self::createAccount($provider, $info);
            if (!$uid) {
                return self::fail('自动创建账号失败，请联系管理员');
            }
            $isNew = true;
        }

        self::bind($uid, $provider, $info);

        $profile = UserService::info($uid);
        if (!$profile) {
            return self::fail('账号信息不存在或已被注销');
        }
        $username = $profile['username'] ?? ('u' . $uid);
        UserSession::create($uid, $username, true, '', 'oauth:' . $provider);

        Logger::info('oauth', '第三方登录成功', [
            'uid' => $uid, 'username' => $username,
            'extra' => ['provider' => $provider, 'is_new' => $isNew],
        ]);

        return [
            'ok'       => true,
            'message'  => $isNew ? '已创建并绑定账号' : '登录成功',
            'uid'      => $uid,
            'username' => $username,
            'is_new'   => $isNew,
            'provider' => $provider,
        ];
    }

    /** 自动创建系统账号（UCenter 建号 + 本地资料） */
    private static function createAccount($provider, array $info)
    {
        $nick = trim((string)($info['nickname'] ?? ''));
        for ($i = 0; $i < 5; $i++) {
            $username = $provider . '_' . substr(md5($provider . $info['openid'] . microtime(true) . $i), 0, 8);
            $pw       = rand_str(16);
            $r = UserService::register([
                'username' => $username,
                'password' => $pw,
                'confirm'  => $pw,
                'email'    => '',            // 留空 → 自动占位邮箱
                'mobile'   => '',
                'nickname' => $nick ?: $username,
            ], false, true);                // force=true：不受开放注册开关限制

            if (!empty($r['ok'])) {
                return intval($r['uid']);
            }
            // 仅用户名冲突时重试，其它错误直接放弃
            $msg = (string)($r['message'] ?? '');
            if (strpos($msg, '已被占用') === false && strpos($msg, '保留字') === false) {
                Logger::error('oauth', '第三方自动建号失败：' . $msg, ['extra' => ['provider' => $provider]]);
                return 0;
            }
        }
        return 0;
    }

    private static function fail($msg)
    {
        return ['ok' => false, 'message' => $msg, 'uid' => 0, 'username' => '', 'is_new' => false];
    }
}
