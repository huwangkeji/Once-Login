<?php
/**
 * UCenter 模拟驱动（开发环境 / 无 UCenter 服务端时使用）
 *
 * 重要：这是「模拟的 UCenter 服务端」。v2 起不再使用独立 SQLite 文件，
 * 模拟用户表 uc_members 与主业务库共用 MySQL（主库连接），
 * 便于统一备份与运维；生产环境请在后台将 UCenter 驱动切换为 http。
 */
class MockDriver implements UCenterDriver
{
    /** @var Database 统一走 Database 封装，保证表前缀被注入 */
    private $db;

    public function __construct(array $cfg)
    {
        $this->db = Database::getInstance();
        // 幂等建表（MySQL 语法；表名走裸名，前缀由 Database::exec 注入）
        $this->db->exec("CREATE TABLE IF NOT EXISTS uc_members (
            uid     INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(64) NOT NULL UNIQUE,
            password VARCHAR(64) NOT NULL,
            email   VARCHAR(191) NOT NULL,
            regip   VARCHAR(64) NOT NULL DEFAULT '',
            regdate INT NOT NULL,
            salt    VARCHAR(32) NOT NULL DEFAULT ''
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        if (!$this->db->hasIndex('uc_members', 'idx_uc_email')) {
            $this->db->exec("CREATE UNIQUE INDEX idx_uc_email ON uc_members(email)");
        }
    }

    private function hashPwd($plain, $salt)
    {
        return md5(md5($plain) . $salt);
    }

    private function salt()
    {
        return substr(uniqid('', true), -6);
    }

    public function call($module, $action, array $args = [])
    {
        if ($module !== 'user') {
            return -7;
        }
        switch ($action) {
            // 规范动作名（与 UCenterClient / HttpDriver 一致）
            case 'check_username':
            // 兼容旧写法（无下划线），避免历史调用方误命中 default 返回 -7
            case 'checkname':
                return $this->checkName($args['username'] ?? '');
            case 'check_email':
            case 'checkemail':
                return $this->checkEmail($args['email'] ?? '');
            case 'register':
                return $this->register(
                    $args['username'] ?? '',
                    $args['password'] ?? '',
                    $args['email'] ?? ''
                );
            case 'login':
                return $this->login(
                    $args['username'] ?? '',
                    $args['password'] ?? '',
                    intval($args['isuid'] ?? 0)
                );
            case 'edit':
                return $this->edit(
                    $args['username'] ?? '',
                    $args['oldpw'] ?? '',
                    $args['newpw'] ?? '',
                    $args['email'] ?? '',
                    intval($args['ignoreoldpw'] ?? 0)
                );
            case 'get_user':
                return $this->getUser($args['username'] ?? '', intval($args['isuid'] ?? 0));
            case 'delete':
                return $this->delete(intval($args['uid'] ?? 0));
            case 'synlogin':
                return '<!-- mock synlogin uid=' . intval($args['uid'] ?? 0) . ' -->';
            case 'synlogout':
                return '<!-- mock synlogout -->';
            default:
                return -7;
        }
    }

    private function findByAccount($account, $isuid = 0)
    {
        if ($isuid === 1) {
            $col = 'uid';
        } elseif (Validator::email($account)) {
            Validator::reset();
            $col = 'email';
        } else {
            $col = 'username';
        }
        $st = $this->db->prepare("SELECT * FROM uc_members WHERE $col = ?");
        $st->execute([$account]);
        return $st->fetch();
    }

    // ---- user.checkname: 1可用 -1非法 -2保护词 -3已存在 ----
    private function checkName($u)
    {
        Validator::reset();
        if (!Validator::username($u)) {
            return -1;
        }
        $bad = ['admin', 'administrator', 'root', 'ucenter'];
        if (in_array(strtolower($u), $bad, true)) {
            return -2;
        }
        $st = $this->db->prepare("SELECT uid FROM uc_members WHERE username=?");
        $st->execute([$u]);
        return $st->fetch() ? -3 : 1;
    }

    // ---- user.checkemail: 1可用 -4格式 -5不允许 -6已存在 ----
    private function checkEmail($e)
    {
        Validator::reset();
        if (!Validator::email($e)) {
            return -4;
        }
        $st = $this->db->prepare("SELECT uid FROM uc_members WHERE email=?");
        $st->execute([$e]);
        return $st->fetch() ? -6 : 1;
    }

    // ---- user.register: >0 uid / -1 -3 -4 -6 ----
    private function register($u, $p, $e)
    {
        Validator::reset();
        if (!Validator::username($u)) {
            return -1;
        }
        Validator::reset();
        if (!Validator::email($e)) {
            return -4;
        }
        $st = $this->db->prepare("SELECT uid FROM uc_members WHERE username=? OR email=?");
        $st->execute([$u, $e]);
        if ($st->fetch()) {
            return -3;
        }
        $salt = $this->salt();
        $st = $this->db->prepare(
            "INSERT INTO uc_members(username,password,email,regip,regdate,salt) VALUES(?,?,?,?,?,?)"
        );
        $st->execute([$u, $this->hashPwd($p, $salt), $e, Request::ip(), time(), $salt]);
        return intval($this->db->getPdo()->lastInsertId());
    }

    // ---- user.login: array(uid,username,password,email,merge) / -1不存在 -2密码错 ----
    private function login($account, $p, $isuid = 0)
    {
        $row = $this->findByAccount($account, $isuid);
        if (!$row) {
            return -1;
        }
        if ($row['password'] !== $this->hashPwd($p, $row['salt'])) {
            return -2;
        }
        return [
            intval($row['uid']),
            $row['username'],
            $row['password'],
            $row['email'],
            0,
        ];
    }

    // ---- user.edit: 1成功 0/-7未修改 -1旧密码错 -4 -6 ----
    private function edit($u, $oldpw, $newpw, $email, $ignoreoldpw = 0)
    {
        $st = $this->db->prepare("SELECT * FROM uc_members WHERE username=?");
        $st->execute([$u]);
        $row = $st->fetch();
        if (!$row) {
            return -1;
        }
        if (!$ignoreoldpw && $oldpw !== '') {
            if ($row['password'] !== $this->hashPwd($oldpw, $row['salt'])) {
                return -1;
            }
        }
        if ($email !== '') {
            Validator::reset();
            if (!Validator::email($email)) {
                return -4;
            }
            $st = $this->db->prepare("SELECT uid FROM uc_members WHERE email=? AND uid<>?");
            $st->execute([$email, $row['uid']]);
            if ($st->fetch()) {
                return -6;
            }
        }
        $salt = $row['salt'];
        $pass = $row['password'];
        if ($newpw !== '') {
            $salt = $this->salt();
            $pass = $this->hashPwd($newpw, $salt);
        }
        $st = $this->db->prepare("UPDATE uc_members SET password=?, email=?, salt=? WHERE uid=?");
        $st->execute([$pass, $email !== '' ? $email : $row['email'], $salt, $row['uid']]);
        return 1;
    }

    // ---- user.get_user: array(uid,username,email) / 0 ----
    private function getUser($account, $isuid = 0)
    {
        $row = $this->findByAccount($account, $isuid);
        if (!$row) {
            return 0;
        }
        return [intval($row['uid']), $row['username'], $row['email']];
    }

    private function delete($uid)
    {
        return $this->db->prepare("DELETE FROM uc_members WHERE uid=?")->execute([$uid]) ? 1 : 0;
    }

    /** 管理员后台「用户查询测试」用（返回带前缀的表名，调用方自行拼 SQL） */
    public function pdo()
    {
        return $this->db->getPdo();
    }

    /** 模拟用户表的真实表名（含前缀），供后台展示 */
    public function tableName()
    {
        return Database::tableName('uc_members');
    }

    public function ping()
    {
        $t = microtime(true);
        try {
            $c = intval($this->db->val("SELECT COUNT(*) c FROM uc_members"));
            return [
                'ok'      => true,
                'message' => 'Mock 驱动运行中（模拟 UCenter，仅供开发调试）',
                'detail'  => ['users' => $c, 'cost_ms' => round((microtime(true) - $t) * 1000, 2)],
            ];
        } catch (Exception $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'detail' => []];
        }
    }
}