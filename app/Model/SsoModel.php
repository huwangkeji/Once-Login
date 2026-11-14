<?php
/**
 * SSO 模型：Ticket（一次性票据）+ Token（长期令牌）
 */
class SsoModel
{
    // ---------- Ticket ----------
    public static function issueTicket($uid, $username, $appId, $callback = '')
    {
        $db  = Database::getInstance();
        $ttl = intval(Config::get('ticket_ttl', 60));
        $tk  = 'TKT_' . rand_str(32);
        $db->insert('tickets', [
            'ticket'     => $tk,
            'uid'        => intval($uid),
            'username'   => $username,
            'app_id'     => $appId,
            'callback'   => $callback,
            'client_ip'  => Request::ip(),
            'created_at' => time(),
            'expires_at' => time() + $ttl,
        ]);
        return $tk;
    }

    /** 消费 ticket：一次性、限时、绑定 app */
    public static function consumeTicket($ticket, $appId)
    {
        $db  = Database::getInstance();
        $row = $db->one("SELECT * FROM tickets WHERE ticket=?", [$ticket]);
        if (!$row) {
            return [false, 'ticket 不存在'];
        }
        if ($row['app_id'] !== $appId) {
            return [false, 'ticket 与应用不匹配'];
        }
        if (intval($row['used']) === 1) {
            return [false, 'ticket 已被使用'];
        }
        if (intval($row['expires_at']) < time()) {
            return [false, 'ticket 已过期'];
        }
        $db->update('tickets', ['used' => 1, 'used_at' => time(), 'used_ip' => Request::ip()], 'ticket=?', [$ticket]);
        return [true, $row];
    }

    // ---------- Token ----------
    public static function issueToken($uid, $username, $appId, $scope = 'base')
    {
        $db  = Database::getInstance();
        $ttl = intval(Config::get('token_ttl', 604800));
        $tk  = 'TOK_' . rand_str(40);
        $db->insert('tokens', [
            'token'      => $tk,
            'uid'        => intval($uid),
            'username'   => $username,
            'app_id'     => $appId,
            'scope'      => $scope,
            'created_at' => time(),
            'expires_at' => time() + $ttl,
            'client_ip'  => Request::ip(),
            'last_at'    => time(),
        ]);
        return ['token' => $tk, 'expires_in' => $ttl, 'expires_at' => time() + $ttl];
    }

    public static function verifyToken($token, $appId = '')
    {
        $row = Database::getInstance()->one("SELECT * FROM tokens WHERE token=?", [$token]);
        if (!$row) return [false, 'token 不存在', null];
        if ($appId !== '' && $row['app_id'] !== $appId) return [false, 'token 与应用不匹配', null];
        if (intval($row['revoked']) === 1) return [false, 'token 已失效（单点退出）', null];
        if (intval($row['expires_at']) < time()) return [false, 'token 已过期', null];
        Database::getInstance()->update('tokens', ['last_at' => time()], 'token=?', [$token]);
        return [true, 'ok', $row];
    }

    public static function revokeToken($token)
    {
        return Database::getInstance()->update('tokens', ['revoked' => 1, 'revoked_at' => time()], 'token=?', [$token]);
    }

    /** 单点退出：吊销该用户在某应用（或全部应用）的 token 与 ticket */
    public static function revokeUser($uid, $appId = '')
    {
        $db    = Database::getInstance();
        $now   = time();
        $count = 0;
        if ($appId !== '') {
            $count += $db->exec("UPDATE tokens SET revoked=1, revoked_at=$now WHERE uid=? AND app_id=? AND revoked=0", [intval($uid), $appId])->rowCount();
            $count += $db->exec("UPDATE tickets SET used=1, used_at=$now WHERE uid=? AND app_id=? AND used=0", [intval($uid), $appId])->rowCount();
        } else {
            $count += $db->exec("UPDATE tokens SET revoked=1, revoked_at=$now WHERE uid=? AND revoked=0", [intval($uid)])->rowCount();
            $count += $db->exec("UPDATE tickets SET used=1, used_at=$now WHERE uid=? AND used=0", [intval($uid)])->rowCount();
        }
        return $count;
    }

    // ---------- 清理 ----------
    public static function gc()
    {
        $db  = Database::getInstance();
        $now = time();
        $db->exec("DELETE FROM tickets WHERE expires_at < $now - 3600");
        $db->exec("DELETE FROM tokens WHERE expires_at < $now - 604800");
        UserSession::cleanExpired();
        $db->exec("DELETE FROM verify_codes WHERE expires_at < $now - 3600");
        return true;
    }

    public static function stats()
    {
        $db = Database::getInstance();
        return [
            'sessions'      => intval($db->val("SELECT COUNT(*) FROM sessions WHERE revoked=0 AND expires_at>" . time())),
            'tokens'        => intval($db->val("SELECT COUNT(*) FROM tokens WHERE revoked=0 AND expires_at>" . time())),
            'tickets_today' => intval($db->val("SELECT COUNT(*) FROM tickets WHERE created_at>" . strtotime('today'))),
            'apps'          => intval($db->val("SELECT COUNT(*) FROM apps WHERE status=1")),
        ];
    }
}
