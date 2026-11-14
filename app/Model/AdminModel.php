<?php
/**
 * 管理员模型（认证中心自身管理员，不进 UCenter）
 */
class AdminModel
{
    public static function findByName($username)
    {
        return Database::getInstance()->one("SELECT * FROM admins WHERE username=?", [$username]);
    }

    public static function findById($id)
    {
        return Database::getInstance()->one("SELECT * FROM admins WHERE id=?", [intval($id)]);
    }

    /**
     * 幂等迁移：为存量库补 admins.email（新装库由 data/schema.sql 直接带出该字段）
     * 仅当字段缺失时执行 DDL；单次请求内用静态变量记忆，避免重复查 information_schema。
     */
    public static function ensureEmailColumn()
    {
        static $done = false;
        if ($done) {
            return true;
        }
        $done = true;
        $db   = Database::getInstance();
        $tbl  = Database::tableName('admins');
        try {
            $st = $db->prepare(
                "SELECT COUNT(*) c FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = ? AND column_name = 'email'"
            );
            $st->execute([$tbl]);
            $row = $st->fetch();
            if (intval($row['c']) > 0) {
                return true;
            }
            $db->exec("ALTER TABLE `$tbl` ADD COLUMN email VARCHAR(191) NOT NULL DEFAULT '' AFTER realname");
            if (!$db->hasIndex('admins', 'idx_admin_email')) {
                $db->exec("ALTER TABLE `$tbl` ADD INDEX idx_admin_email (email)");
            }
            Logger::info('admin', 'admins 表已补齐 email 字段（管理员找回密码依赖）');
        } catch (Throwable $e) {
            // 迁移失败（如数据库账号无 DDL 权限）不阻断主流程，调用方按「无邮箱」降级处理
            Logger::warn('admin', 'admins.email 迁移失败：' . $e->getMessage());
            return false;
        }
        return true;
    }

    public static function all()
    {
        self::ensureEmailColumn();
        return Database::getInstance()->query("SELECT id,username,realname,email,role,status,last_login_at,last_login_ip,created_at FROM admins ORDER BY id ASC");
    }

    /** 按邮箱定位管理员（找回密码用；空邮箱不参与匹配） */
    public static function findByEmail($email)
    {
        $email = trim((string)$email);
        if ($email === '') {
            return null;
        }
        self::ensureEmailColumn();
        return Database::getInstance()->one("SELECT * FROM admins WHERE email=? ORDER BY id ASC LIMIT 1", [$email]);
    }

    /**
     * 找回密码场景：凭邮箱验证码直接重置（不校验原密码）
     * 同时清理该管理员的登录失败锁定，并递增会话版本号强制其它设备后台登录失效。
     */
    public static function resetPassword($id, $newPassword)
    {
        $row = self::findById($id);
        if (!$row) {
            return [false, '管理员不存在'];
        }
        $db = Database::getInstance();
        $db->update('admins', [
            'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
            'updated_at'    => time(),
        ], 'id=?', [intval($id)]);
        // 找回成功后解除可能的失败锁定，避免重置完仍被拦截
        $db->delete('login_fail', 'account=?', ['admin:' . $row['username']]);
        // 会话版本号 +1：其它设备已登录的后台会话在下一次请求时被判定失效
        $ver = intval(Config::get('admin_sess_ver_' . intval($row['id']), 0)) + 1;
        Config::set('admin_sess_ver_' . intval($row['id']), $ver, 'admin_sess', '管理员会话版本号（改密/找回后强制下线）');
        return [true, 'ok'];
    }

    public static function login($username, $password)
    {
        $username = trim((string)$username);
        // L4：管理员登录失败锁定（复用 login_fail 表，键加 admin: 前缀防止与前台用户键冲突）
        $lockKey = 'admin:' . $username;
        $db      = Database::getInstance();
        $lockRow = $db->one("SELECT * FROM login_fail WHERE account=?", [$lockKey]);
        if ($lockRow && intval($lockRow['lock_until']) > time()) {
            return [false, '登录失败次数过多，请 ' . ceil((intval($lockRow['lock_until']) - time()) / 60) . ' 分钟后重试', null];
        }
        $row = self::findByName($username);
        if (!$row) { self::failLogin($lockKey, '管理员不存在'); return [false, '管理员不存在', null]; }
        if (!$row['status']) { self::failLogin($lockKey, '该管理员已被禁用'); return [false, '该管理员已被禁用', null]; }
        if (!password_verify($password, $row['password_hash'])) { self::failLogin($lockKey, '密码错误'); return [false, '密码错误', null]; }
        self::clearLogin($lockKey);
        $db->update('admins', [
            'last_login_at' => time(),
            'last_login_ip' => Request::ip(),
        ], 'id=?', [$row['id']]);
        self::touch();
        return [true, 'ok', $row];
    }

    // L4：管理员登录失败计数（达阈值即锁定 login_lock_time 秒）
    private static function failLogin($lockKey, $msg)
    {
        $db = Database::getInstance();
        $now = time();
        $ip  = Request::ip();
        // 先 UPDATE 再 INSERT IGNORE：必须走 $db->prepare()，自动注入表前缀
        $st = $db->prepare("UPDATE login_fail SET fail_count=fail_count+1, last_at=?, last_ip=? WHERE account=?");
        $st->execute([$now, $ip, $lockKey]);
        if ($st->rowCount() === 0) {
            $db->prepare(
                "INSERT IGNORE INTO login_fail(account,fail_count,lock_until,last_at,last_ip) VALUES(?,1,0,?,?)"
            )->execute([$lockKey, $now, $ip]);
        }
        $row = $db->one("SELECT * FROM login_fail WHERE account=?", [$lockKey]);
        if ($row && intval($row['fail_count']) >= intval(Config::get('login_fail_limit', 5))) {
            $db->update('login_fail',
                ['lock_until' => time() + intval(Config::get('login_lock_time', 900)), 'fail_count' => 0],
                'account=?', [$lockKey]);
        }
        Logger::warn('admin', '管理员登录失败：' . $msg, ['target' => $lockKey, 'extra' => ['ip' => Request::ip()]]);
    }

    // L4：管理员登录成功清除锁定记录
    private static function clearLogin($lockKey)
    {
        Database::getInstance()->delete('login_fail', 'account=?', [$lockKey]);
    }

    public static function create($username, $password, $realname = '', $role = 'admin', $email = '')
    {
        if (self::findByName($username)) {
            return [false, '管理员账号已存在', 0];
        }
        self::ensureEmailColumn();
        $id = Database::getInstance()->insert('admins', [
            'username'      => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'realname'      => $realname,
            'email'         => trim((string)$email),
            'role'          => $role,
            'permissions'   => json_encode(['*']),
            'status'        => 1,
            'created_at'    => time(),
            'updated_at'    => time(),
        ]);
        return [true, 'ok', $id];
    }

    public static function changePassword($id, $oldPassword, $newPassword)
    {
        $row = self::findById($id);
        if (!$row) return [false, '管理员不存在'];
        if (!password_verify($oldPassword, $row['password_hash'])) return [false, '原密码不正确'];
        Database::getInstance()->update('admins', [
            'password_hash'    => password_hash($newPassword, PASSWORD_DEFAULT),
            'force_pwd_change' => 0,   // 兼容：清理历史遗留的首登强制改密标记（该功能已移除，无拦截逻辑）
            'updated_at'       => time(),
        ], 'id=?', [intval($id)]);
        return [true, 'ok'];
    }

    public static function toggle($id, $status)
    {
        if (intval($id) === 1) {
            return false;
        }
        return Database::getInstance()->update('admins', ['status' => intval($status), 'updated_at' => time()], 'id=?', [intval($id)]);
    }

    public static function remove($id)
    {
        if (intval($id) === 1) {
            return false;
        }
        return Database::getInstance()->delete('admins', 'id=?', [intval($id)]);
    }

    // ---------- 会话 ----------
    public static function touch()
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION['uic_admin_at'] = time();
    }

    public static function current()
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        if (empty($_SESSION['uic_admin_id'])) {
            return null;
        }
        if (($now = time()) - intval($_SESSION['uic_admin_at'] ?? 0) > 7200) {
            self::logout();
            return null;
        }
        // 会话版本号：找回密码/重置后递增，使其它设备已登录的后台会话立即失效
        $ver   = intval(Config::get('admin_sess_ver_' . intval($_SESSION['uic_admin_id']), 0));
        $sessV = intval($_SESSION['uic_admin_ver'] ?? -1);
        if ($sessV !== -1 && $sessV !== $ver) {
            self::logout();
            return null;
        }
        $_SESSION['uic_admin_ver'] = $ver;
        $_SESSION['uic_admin_at']  = time();
        return self::findById($_SESSION['uic_admin_id']);
    }

    public static function authorize($row)
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION['uic_admin_id']   = $row['id'];
        $_SESSION['uic_admin']      = $row['username'];
        $_SESSION['uic_admin_role'] = $row['role'];
        $_SESSION['uic_admin_ver']  = intval(Config::get('admin_sess_ver_' . intval($row['id']), 0));
        self::touch();
    }

    public static function logout()
    {
        unset($_SESSION['uic_admin_id'], $_SESSION['uic_admin'], $_SESSION['uic_admin_role'], $_SESSION['uic_admin_at'], $_SESSION['uic_admin_ver']);
    }
}

