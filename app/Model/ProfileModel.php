<?php
/**
 * 用户扩展资料模型（非核心身份数据，UCenter 以外的补充字段）
 */
class ProfileModel
{
    public static function get($uid)
    {
        return Database::getInstance()->one("SELECT * FROM user_profiles WHERE uid=?", [intval($uid)]);
    }

    public static function save($uid, $data)
    {
        $db   = Database::getInstance();
        $exist = self::get($uid);
        $time = time();
        if ($exist) {
            $upd = array_merge($data, ['updated_at' => $time]);
            return $db->update('user_profiles', $upd, 'uid=?', [intval($uid)]);
        }
        $ins = array_merge([
            'uid'         => intval($uid),
            'username'    => '',
            'nickname'    => '',
            'avatar'      => '',
            'gender'      => 0,
            'mobile'      => '',
            'birthday'    => '',
            'bio'         => '',
            'sync_status' => 0,
            'created_at'  => $time,
            'updated_at'  => $time,
        ], $data);
        return $db->insert('user_profiles', $ins);
    }

    public static function findByMobile($mobile)
    {
        return Database::getInstance()->one("SELECT * FROM user_profiles WHERE mobile=?", [$mobile]);
    }

    public static function markSync($uid, $status, $msg = '')
    {
        return Database::getInstance()->update(
            'user_profiles',
            ['sync_status' => intval($status), 'sync_msg' => $msg, 'synced_at' => time()],
            'uid=?',
            [intval($uid)]
        );
    }

    /** 头像 URL：未设置则生成首字母渐变占位图 */
    public static function avatarUrl($profile, $username = '')
    {
        if (!empty($profile['avatar'])) {
            return $profile['avatar'];
        }
        $nick = isset($profile['nickname']) ? trim((string)$profile['nickname']) : '';
        $ch   = mb_substr($nick !== '' ? $nick : ($username ?: 'U'), 0, 1, 'UTF-8');
        return 'data:image/svg+xml;charset=utf-8,' . rawurlencode(
            '<svg xmlns="http://www.w3.org/2000/svg" width="96" height="96">'
            . '<rect width="96" height="96" rx="48" fill="#2563eb"/>'
            . '<text x="48" y="62" font-size="44" fill="#fff" text-anchor="middle" font-family="sans-serif">'
            . htmlspecialchars($ch, ENT_QUOTES) . '</text></svg>'
        );
    }

    /** 已注册的 userId 列表搜索（本地扩展资料层面） */
    public static function search($kw = '', $page = 1, $size = 20)
    {
        $db    = Database::getInstance();
        $w     = '1=1';
        $args  = [];
        if ($kw !== '') {
            $w = '(username LIKE ? OR nickname LIKE ? OR mobile LIKE ?)';
            $kw = "%$kw%";
            $args = [$kw, $kw, $kw];
        }
        $offset = (max(1, intval($page)) - 1) * intval($size);
        $total  = intval($db->val("SELECT COUNT(*) FROM user_profiles WHERE $w", $args));
        $rows   = $db->query("SELECT * FROM user_profiles WHERE $w ORDER BY uid DESC LIMIT " . intval($size) . " OFFSET $offset", $args);
        return ['total' => $total, 'rows' => $rows];
    }
}
