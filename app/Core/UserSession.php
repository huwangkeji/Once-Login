<?php
/**
 * 用户会话管理（统一 Session）
 * 会话数据落 MySQL sessions 表，Cookie 只保存不可猜测的 sid
 */
class UserSession
{
    const COOKIE      = 'uic_sid';
    const REMEMBER    = 'uic_rid';

    private static $current = null;

    /** 创建会话 */
    public static function create($uid, $username, $remember = false, $appId = '', $source = 'center')
    {
        $db      = Database::getInstance();
        $sid     = rand_str(48);
        $ttl     = $remember
            ? intval(Config::get('remember_ttl', 2592000))
            : intval(Config::get('session_ttl', 7200));
        $now     = time();
        $ua      = Request::ua();
        $db->insert('sessions', [
            'sid'          => $sid,
            'uid'          => intval($uid),
            'username'     => $username,
            'source'       => $source,
            'app_id'       => $appId,
            'device_id'    => self::deviceId(),
            'device_label' => self::deviceLabel($ua),
            'login_ip'     => Request::ip(),
            'login_ua'     => $ua,
            'last_ip'      => Request::ip(),
            'login_at'     => $now,
            'last_at'      => $now,
            'expires_at'   => $now + $ttl,
            'remember'     => $remember ? 1 : 0,
        ]);
        self::setCookie(self::COOKIE, $sid, $ttl);
        return $sid;
    }

    /** 解析当前会话 */
    public static function current()
    {
        if (self::$current !== null) {
            return self::$current;
        }
        $sid = $_COOKIE[self::COOKIE] ?? '';
        if (!$sid) {
            return self::$current = null;
        }
        $db  = Database::getInstance();
        $row = $db->one("SELECT * FROM sessions WHERE sid=? AND revoked=0 AND expires_at>?", [$sid, time()]);
        if (!$row) {
            self::unsetCookie(self::COOKIE);
            return self::$current = null;
        }
        // L5：滑动续期——活跃访问自动顺延过期时间
        // 续期目标 = now + ttl，但设绝对上限(login_at + 2×ttl)，防止休眠会话被无限续期
        $now = time();
        $ttl = intval($row['remember']
            ? Config::get('remember_ttl', 2592000)
            : Config::get('session_ttl', 7200));
        $maxExpire = intval($row['login_at']) + 2 * $ttl;
        $newExpire = min($now + $ttl, max($maxExpire, intval($row['expires_at'])));
        if ($newExpire > intval($row['expires_at'])) {
            $db->update('sessions', [
                'last_at' => $now, 'last_ip' => Request::ip(), 'expires_at' => $newExpire,
            ], 'sid=?', [$sid]);
        } else {
            $db->update('sessions', ['last_at' => $now, 'last_ip' => Request::ip()], 'sid=?', [$sid]);
        }
        return self::$current = $row;
    }

    public static function uid()
    {
        $c = self::current();
        return $c ? intval($c['uid']) : 0;
    }

    public static function sid()
    {
        $c = self::current();
        return $c ? $c['sid'] : '';
    }

    /** 校验 UCenter 侧依然有效（每 5 分钟一次，避免频繁请求） */
    public static function recheckWithUCenter()
    {
        $c = self::current();
        if (!$c) {
            return false;
        }
        $cacheKey = 'uc_recheck_' . $c['sid'];
        if (isset($GLOBALS[$cacheKey]) && $GLOBALS[$cacheKey] > time()) {
            return true;
        }
        try {
            $uc = new UCenterClient();
            $info = $uc->getByUid($c['uid']);
            $ok = !empty($info) && intval($info[0]) === intval($c['uid']);
            $GLOBALS[$cacheKey] = time() + 300;
            if (!$ok) {
                self::destroy($c['sid']);
                self::unsetCookie(self::COOKIE);
                self::$current = null;
            }
            return $ok;
        } catch (Exception $e) {
            return true; // UC 不可用时不断开本地会话，降级处理
        }
    }

    /** 销毁单个会话 */
    public static function destroy($sid)
    {
        Database::getInstance()->update(
            'sessions',
            ['revoked' => 1, 'revoked_at' => time()],
            'sid=?',
            [$sid]
        );
        return true;
    }

    /** 退出全部设备 */
    public static function destroyAll($uid, $exceptSid = '')
    {
        $db = Database::getInstance();
        $sql = "UPDATE sessions SET revoked=1, revoked_at=? WHERE uid=? AND revoked=0";
        $args = [time(), intval($uid)];
        if ($exceptSid !== '') {
            $sql .= " AND sid<>?";
            $args[] = $exceptSid;
        }
        return $db->exec($sql, $args)->rowCount();
    }

    public static function cleanExpired()
    {
        // 返回影响行数，供运维清理工具（tools/log_rotate.php）统计
        return Database::getInstance()->exec("DELETE FROM sessions WHERE expires_at<? AND revoked=0", [time()])->rowCount();
    }

    // ---------- Cookie ----------
    public static function setCookie($name, $value, $ttl)
    {
        // 反向代理（Nginx/SLB/网关）终止 TLS 后，后端只看到 HTTP；
        // 必须以 X-Forwarded-Proto: https 判定真实协议，否则 Secure 标记缺失、Cookie 在 HTTPS 站点泄露。
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
                && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
        $secure = (bool) $isHttps;
        $samesite = 'Lax';
        if (PHP_SAPI !== 'cli') {
            if (PHP_VERSION_ID >= 70300) {
                setcookie($name, $value, [
                    'expires'  => time() + $ttl,
                    'path'     => '/',
                    'domain'   => '',
                    'secure'   => $secure,
                    'httponly' => true,
                    'samesite' => $samesite,
                ]);
            } else {
                // PHP < 7.3 不支持数组形式，samesite 需手动拼接到 Set-Cookie 头
                $cookie = sprintf(
                    '%s=%s; expires=%s; Max-Age=%d; path=/; %s%sSameSite=%s',
                    rawurlencode($name), rawurlencode($value),
                    gmdate('D, d-M-Y H:i:s T', time() + $ttl),
                    $ttl,
                    $secure ? 'secure; ' : '',
                    'httponly; ',
                    $samesite
                );
                header('Set-Cookie: ' . $cookie, false);
            }
        }
        $_COOKIE[$name] = $value;
    }

    public static function unsetCookie($name)
    {
        // CLI（如 tools/selftest.php）下调用 setcookie 会产生 headers already sent 警告
        if (PHP_SAPI !== 'cli') {
            setcookie($name, '', time() - 3600, '/', '', false, true);
        }
        unset($_COOKIE[$name]);
    }

    public static function flush()
    {
        self::$current = null;
    }

    // ---------- 设备 ----------
    public static function deviceId()
    {
        return substr(md5(Request::ua() . '|' . Request::ip()), 0, 16);
    }

    public static function deviceLabel($ua = null)
    {
        $ua = $ua ?: Request::ua();
        $os = '未知系统';
        if (stripos($ua, 'Windows NT 10') !== false) $os = 'Windows 10/11';
        elseif (stripos($ua, 'Windows NT 6.3') !== false) $os = 'Windows 8.1';
        elseif (stripos($ua, 'Windows') !== false) $os = 'Windows';
        elseif (stripos($ua, 'iPhone') !== false) $os = 'iPhone';
        elseif (stripos($ua, 'iPad') !== false) $os = 'iPad';
        elseif (stripos($ua, 'Android') !== false) $os = 'Android';
        elseif (stripos($ua, 'Mac OS X') !== false) $os = 'macOS';
        elseif (stripos($ua, 'Linux') !== false) $os = 'Linux';

        $browser = '未知浏览器';
        if (stripos($ua, 'Edg/') !== false) $browser = 'Edge';
        elseif (stripos($ua, 'MicroMessenger') !== false) $browser = '微信内置浏览器';
        elseif (stripos($ua, 'Chrome') !== false) $browser = 'Chrome';
        elseif (stripos($ua, 'Firefox') !== false) $browser = 'Firefox';
        elseif (stripos($ua, 'Safari') !== false) $browser = 'Safari';
        elseif (stripos($ua, 'PostmanRuntime') !== false) $browser = 'Postman';
        elseif (stripos($ua, 'curl') !== false) $browser = 'cURL';

        return $os . ' · ' . $browser;
    }
}
