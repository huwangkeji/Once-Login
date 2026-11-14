<?php
/**
 * 应用（接入站点）模型
 */
class AppModel
{
    /** 生成 AppID / AppSecret */
    public static function genAppId()
    {
        return 'app_' . substr(bin2hex(random_bytes(8)), 0, 12);
    }

    public static function genAppSecret()
    {
        return bin2hex(random_bytes(20));
    }

    public static function all($onlyEnabled = false)
    {
        $db = Database::getInstance();
        $w  = $onlyEnabled ? ' WHERE status=1' : '';
        $rows = $db->query("SELECT * FROM apps $w ORDER BY sort_order DESC, id DESC");
        foreach ($rows as &$r) {
            $r['callbacks'] = json_decode($r['callbacks'] ?: '[]', true);
        }
        return $rows;
    }

    public static function findByAppId($appId)
    {
        $r = Database::getInstance()->one("SELECT * FROM apps WHERE app_id=?", [$appId]);
        if ($r) {
            $r['callbacks'] = json_decode($r['callbacks'] ?: '[]', true);
        }
        return $r;
    }

    public static function findById($id)
    {
        $r = Database::getInstance()->one("SELECT * FROM apps WHERE id=?", [intval($id)]);
        if ($r) {
            $r['callbacks'] = json_decode($r['callbacks'] ?: '[]', true);
        }
        return $r;
    }

    public static function create($data)
    {
        $db = Database::getInstance();
        $now = time();
        return $db->insert('apps', [
            'app_id'      => $data['app_id'] ?? '' ?: self::genAppId(),
            'app_secret'  => $data['app_secret'] ?? '' ?: self::genAppSecret(),
            'name'        => $data['name'] ?? '',
            'description' => $data['description'] ?? '',
            'home_url'    => $data['home_url'] ?? '',
            'callbacks'   => json_encode($data['callbacks'] ?? [], JSON_UNESCAPED_UNICODE),
            'status'      => intval($data['status'] ?? 1),
            'sso_enabled' => intval($data['sso_enabled'] ?? 1),
            'sort_order'  => intval($data['sort_order'] ?? 0),
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);
    }

    public static function update($id, $data)
    {
        $allowed = ['name', 'description', 'home_url', 'callbacks', 'status', 'sso_enabled', 'sort_order', 'ip_whitelist'];
        $upd = ['updated_at' => time()];
        foreach ($allowed as $k) {
            if (array_key_exists($k, $data)) {
                $upd[$k] = in_array($k, ['callbacks', 'ip_whitelist'])
                    ? json_encode($data[$k], JSON_UNESCAPED_UNICODE)
                    : $data[$k];
            }
        }
        return Database::getInstance()->update('apps', $upd, 'id=?', [intval($id)]);
    }

    public static function resetSecret($id)
    {
        $s = self::genAppSecret();
        Database::getInstance()->update('apps', ['app_secret' => $s, 'updated_at' => time()], 'id=?', [intval($id)]);
        return $s;
    }

    public static function toggle($id, $status)
    {
        return Database::getInstance()->update('apps', ['status' => intval($status), 'updated_at' => time()], 'id=?', [intval($id)]);
    }

    public static function remove($id)
    {
        $app = self::findById($id);
        Database::getInstance()->exec("DELETE FROM tokens WHERE app_id=?", [$app['app_id'] ?? '']);
        Database::getInstance()->exec("DELETE FROM tickets WHERE app_id=?", [$app['app_id'] ?? '']);
        return Database::getInstance()->delete('apps', 'id=?', [intval($id)]);
    }

    /** 回调地址是否在白名单内 */
    public static function allowCallback($app, $callback)
    {
        if (empty($callback)) {
            return false;
        }
        $list = is_array($app['callbacks']) ? $app['callbacks'] : json_decode($app['callbacks'] ?: '[]', true);
        foreach ($list as $c) {
            if (strtolower(rtrim($c, '/')) === strtolower(rtrim($callback, '/'))) {
                return true;
            }
        }
        return false;
    }

    private static function _cleanGrant($appId)
    {
        return true;
    }
}
