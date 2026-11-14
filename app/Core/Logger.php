<?php
/**
 * 统一日志：写入 MySQL logs 表
 */
class Logger
{
    const TYPES = [
        'register', 'login', 'logout', 'password', 'findpwd',
        'profile', 'api', 'ucenter', 'admin', 'sso', 'app', 'system',
    ];

    /**
     * @param string $type
     * @param string $message
     * @param array  $ctx  uid/username/target/level/app_id/admin_id/extra
     */
    public static function write($type, $message, array $ctx = [])
    {
        try {
            $db = Database::getInstance();
            $db->insert('logs', [
                'type'      => $type,
                'level'     => $ctx['level'] ?? 'info',
                'uid'       => intval($ctx['uid'] ?? 0),
                'username'  => $ctx['username'] ?? '',
                'target'    => $ctx['target'] ?? '',
                'message'   => $message,
                'ip'        => Request::ip(),
                'ua'        => Request::ua(),
                'app_id'    => $ctx['app_id'] ?? '',
                'admin_id'  => intval($ctx['admin_id'] ?? 0),
                'extra'     => isset($ctx['extra']) ? json_encode($ctx['extra'], JSON_UNESCAPED_UNICODE) : '',
                'created_at'=> time(),
            ]);
        } catch (Exception $e) {
            @error_log('[Logger] ' . $e->getMessage());
        }
    }

    public static function info($type, $msg, $ctx = [])
    {
        $ctx['level'] = 'info';
        self::write($type, $msg, $ctx);
    }

    public static function warn($type, $msg, $ctx = [])
    {
        $ctx['level'] = 'warn';
        self::write($type, $msg, $ctx);
    }

    public static function error($type, $msg, $ctx = [])
    {
        $ctx['level'] = 'error';
        self::write($type, $msg, $ctx);
    }

    /** 分页查询日志 */
    public static function search($cond = [], $page = 1, $size = 20)
    {
        $db   = Database::getInstance();
        $w    = ['1=1'];
        $args = [];
        if (!empty($cond['type'])) {
            $w[] = 'type = ?';
            $args[] = $cond['type'];
        }
        if (!empty($cond['level'])) {
            $w[] = 'level = ?';
            $args[] = $cond['level'];
        }
        if (!empty($cond['uid'])) {
            $w[] = 'uid = ?';
            $args[] = intval($cond['uid']);
        }
        if (!empty($cond['app_id'])) {
            $w[] = 'app_id = ?';
            $args[] = $cond['app_id'];
        }
        if (!empty($cond['kw'])) {
            $w[] = '(message LIKE ? OR username LIKE ? OR target LIKE ? OR ip LIKE ?)';
            $kw = '%' . $cond['kw'] . '%';
            $args = array_merge($args, [$kw, $kw, $kw, $kw]);
        }
        $where = implode(' AND ', $w);
        $total = intval($db->val("SELECT COUNT(*) FROM logs WHERE $where", $args));
        $offset = (max(1, intval($page)) - 1) * intval($size);
        $rows = $db->query("SELECT * FROM logs WHERE $where ORDER BY id DESC LIMIT " . intval($size) . " OFFSET $offset", $args);
        foreach ($rows as &$r) {
            $r['extra'] = json_decode($r['extra'] ?: '[]', true);
        }
        return ['total' => $total, 'rows' => $rows, 'page' => intval($page), 'size' => intval($size)];
    }

    /** 清理 N 天前的日志 */
    public static function purge($days = 90)
    {
        $cut = time() - intval($days) * 86400;
        return Database::getInstance()->exec("DELETE FROM logs WHERE created_at < ?", [$cut])->rowCount();
    }
}
