<?php
/**
 * MySQL PDO 单例 + 自动建表（兼容原 SQLite 版调用契约）
 *
 * 相比 SQLite 版的差异处理：
 *   1. 自增主键：SQLite AUTOINCREMENT → MySQL AUTO_INCREMENT
 *   2. 文本主键：SQLite TEXT PRIMARY KEY → MySQL 必须用 VARCHAR(n) 才能建索引
 *   3. 时间存储：统一用 INT 存 Unix 时间戳，不依赖数据库时区，也不使用 NOW()
 *   4. 字符串转义：全部走 PDO 预处理（ATTR_EMULATE_PREPARES=false），不手工 addslashes
 *   5. 建表幂等：MySQL 5.7 不支持 CREATE INDEX IF NOT EXISTS，靠捕获 Duplicate key name 忽略
 *   6. 表前缀：支持 db.prefix，由本类统一注入，业务 SQL 仍写裸表名
 *   7. 事务：提供 transaction()/beginTransaction()/commit()/rollBack()
 *
 * 调用契约（与旧版一致，外部无需改动）：
 *   getInstance / getPdo / query / one / val / exec / insert / update / delete / migrate / getErrors
 */
class Database
{
    private static $instance = null;
    private $pdo;
    private $log  = [];
    private $cfg  = [];

    /** 需要加前缀的业务表名白名单（避免误替换普通标识符） */
    const TABLES = [
        'settings', 'apps', 'sessions', 'tickets', 'tokens', 'user_profiles',
        'verify_codes', 'captcha_tokens', 'logs', 'admins', 'uc_calls',
        'uc_notices', 'login_fail', 'uc_members',
        // 第三方登录绑定 / 账号注销申请 / 账号回收站
        'user_oauth', 'account_deletion', 'user_recycle',
        // 通行密钥 / WebAuthn（凭证与流程挑战）
        'user_passkeys', 'webauthn_challenges',
        // 会员中心侧边栏产品展示（后台可配置）
        'member_products',
    ];

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /** 读取数据库配置（config.php 的 db 段 + data/db_cred.php 覆盖） */
    private static function loadConfig()
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $cfg = require dirname(__DIR__, 2) . '/config/config.php';
        $db  = isset($cfg['db']) && is_array($cfg['db']) ? $cfg['db'] : [];
        return $cached = [
            'host'    => !empty($db['host']) ? $db['host'] : '127.0.0.1',
            'port'    => intval(!empty($db['port']) ? $db['port'] : 3306),
            'dbname'  => !empty($db['dbname']) ? $db['dbname'] : '',
            'user'    => isset($db['user']) ? $db['user'] : '',
            'pass'    => isset($db['pass']) ? $db['pass'] : '',
            'charset' => !empty($db['charset']) ? $db['charset'] : 'utf8mb4',
            'prefix'  => isset($db['prefix']) ? trim((string)$db['prefix']) : '',
        ];
    }

    private function __construct()
    {
        $this->cfg = self::loadConfig();
        if ($this->cfg['dbname'] === '') {
            throw new RuntimeException('尚未配置数据库：请先访问 install.php 完成安装。');
        }
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $this->cfg['host'], $this->cfg['port'], $this->cfg['dbname'], $this->cfg['charset']
        );
        try {
            $this->pdo = new PDO($dsn, $this->cfg['user'], $this->cfg['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 8,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException(self::friendlyError($e, $this->cfg));
        }
        $this->pdo->exec("SET NAMES " . $this->cfg['charset']);
        // 只放宽会影响旧写法的两项，其余模式（如 NO_ZERO_DATE）保留，避免一刀清空
        $mode  = (string)$this->pdo->query("SELECT @@SESSION.sql_mode")->fetchColumn();
        $drop  = ['ONLY_FULL_GROUP_BY', 'STRICT_TRANS_TABLES', 'STRICT_ALL_TABLES'];
        $parts = array_values(array_filter(array_diff(explode(',', $mode), $drop)));
        $this->pdo->exec("SET SESSION sql_mode = '" . str_replace("'", '', implode(',', $parts)) . "'");
        if ($this->isEmpty()) {
            $this->migrate();
        }
    }

    /** 把 PDO 连接异常翻译成人能看懂的中文提示 */
    public static function friendlyError(PDOException $e, array $cfg = [])
    {
        $code = isset($e->errorInfo[1]) ? intval($e->errorInfo[1]) : intval($e->getCode());
        $msg  = $e->getMessage();
        switch ($code) {
            case 1045:
                return '数据库账号或密码错误（MySQL 1045）。请检查 data/db_cred.php 中的 user / pass。';
            case 1049:
                return '数据库不存在（MySQL 1049）：' . ($cfg['dbname'] ?? '') . '。请先在 MySQL 中创建该库，或回到 install.php 重新配置。';
            case 2002:
            case 2003:
                return '无法连接数据库服务器（MySQL ' . $code . '）：' . ($cfg['host'] ?? '') . ':' . ($cfg['port'] ?? 3306)
                    . '。请确认 MySQL 已启动、地址与端口正确、防火墙已放行。';
            case 1044:
                return '该数据库用户没有访问权限（MySQL 1044）。请确认已对 ' . ($cfg['dbname'] ?? '') . ' 库授权。';
            default:
                return '数据库连接失败：' . $msg;
        }
    }

    // =========================================================
    //  表前缀
    // =========================================================

    public static function prefix()
    {
        static $p = null;
        if ($p === null) {
            $p = self::loadConfig()['prefix'];
        }
        return $p;
    }

    /** 取带前缀的表名（带反引号，避免与保留字冲突） */
    public static function table($name)
    {
        $name = trim((string)$name);
        if (in_array($name, self::TABLES, true)) {
            return '`' . self::prefix() . $name . '`';
        }
        return '`' . $name . '`';
    }

    /**
     * 给原始 SQL 注入表前缀
     * 只在 FROM / JOIN / INTO / UPDATE / TABLE / TABLE IF NOT EXISTS / ON 之后替换，
     * 且只替换白名单内的表名，因此不会误伤列名与字符串字面量。
     */
    public static function applyPrefix($sql)
    {
        return self::applyPrefixFor($sql, self::prefix());
    }

    /**
     * 用指定前缀改写 SQL（安装阶段配置尚未落盘，需要显式传前缀）
     */
    public static function applyPrefixFor($sql, $prefix)
    {
        $pre = trim((string)$prefix);
        if ($pre === '') {
            return $sql;
        }
        $tables = self::TABLES;
        $pattern = '/\b(?:TABLE\s+IF\s+NOT\s+EXISTS|FROM|JOIN|INTO|UPDATE|TABLE|ON)\s+`?([A-Za-z_][A-Za-z0-9_]*)`?/i';
        return preg_replace_callback($pattern, function ($m) use ($pre, $tables) {
            $t = $m[1];
            if (!in_array($t, $tables, true)) {
                return $m[0];
            }
            // 用字符串拼接而非 preg_replace 的替换串：前缀里若含 $ 或 \ 会被当成反向引用
            $pos = strlen($m[0]) - strlen($t);
            return substr($m[0], 0, $pos) . '`' . $pre . $t . '`';
        }, $sql);
    }

    /** 供安装向导校验：给定前缀后的实际表名 */
    public static function tableName($name, $prefix = null)
    {
        return ($prefix === null ? self::prefix() : trim((string)$prefix)) . $name;
    }

    /** 给 insert/update/delete 的表名参数加前缀 */
    private function tbl($name)
    {
        return self::table($name);
    }

    // =========================================================
    //  建表 / 迁移
    // =========================================================

    /**
     * 是否尚未建表
     * 注意：不要统计「库内所有表」——共用库里可能有别的表，
     * 那样会误判为已安装而跳过建表。这里只精确判断核心表 settings。
     */
    private function isEmpty()
    {
        try {
            $st = $this->pdo->prepare(
                "SELECT COUNT(*) c FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = ?"
            );
            $st->execute([self::prefix() . 'settings']);
            $row = $st->fetch();
            return intval($row['c']) === 0;
        } catch (Exception $e) {
            return true;
        }
    }

    /** 执行 schema（MySQL 语法），幂等 */
    public function migrate($schemaFile = null)
    {
        $schemaFile = $schemaFile ?: dirname(__DIR__, 2) . '/data/schema.sql';
        if (!file_exists($schemaFile)) {
            throw new RuntimeException('schema not found: ' . $schemaFile);
        }
        $sql = file_get_contents($schemaFile);
        $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql);
        $sql = self::applyPrefix($sql);
        foreach (explode(';', $sql) as $stmt) {
            $clean = [];
            foreach (preg_split('/\r?\n/', $stmt) as $line) {
                if (strpos(ltrim($line), '--') === 0) {
                    continue;
                }
                $clean[] = $line;
            }
            $stmt = trim(implode("\n", $clean));
            if ($stmt === '') {
                continue;
            }
            try {
                $this->pdo->exec($stmt);
            } catch (PDOException $e) {
                // MySQL 不支持 CREATE INDEX IF NOT EXISTS，重复执行时报 Duplicate key name
                $msg = $e->getMessage();
                if (strpos($msg, 'Duplicate key name') === false && strpos($msg, 'already exists') === false) {
                    $this->log[] = $msg;
                }
            }
        }
        // 注意：首次建库时不再自动播种弱口令管理员（admin/admin123）。
        // 管理员账户由安装向导 install.php 按强口令策略创建，并写入 install.lock；
        // 此处若再播种已知凭据会绕过安装策略，构成越权登录风险。
        return true;
    }

    // =========================================================
    //  事务
    // =========================================================

    public function beginTransaction()
    {
        if (!$this->pdo->inTransaction()) {
            return $this->pdo->beginTransaction();
        }
        return false;
    }

    public function commit()
    {
        if ($this->pdo->inTransaction()) {
            return $this->pdo->commit();
        }
        return false;
    }

    public function rollBack()
    {
        if ($this->pdo->inTransaction()) {
            return $this->pdo->rollBack();
        }
        return false;
    }

    /** 闭包事务：异常自动回滚并继续抛出 */
    public function transaction(callable $fn)
    {
        $this->beginTransaction();
        try {
            $r = $fn($this);
            $this->commit();
            return $r;
        } catch (Exception $e) {
            $this->rollBack();
            throw $e;
        }
    }

    // =========================================================
    //  便捷方法（调用契约与 SQLite 版完全一致）
    // =========================================================

    public function getPdo()
    {
        return $this->pdo;
    }

    /**
     * 前缀感知的 prepare（返回 PDOStatement）
     *
     * 业务代码凡是需要 PDOStatement（rowCount / fetch 逐个处理 / lastInsertId 之外）时，
     * 请一律用 $db->prepare()，不要用 $db->getPdo()->prepare()——后者会绕过表前缀注入，
     * 在设置了 db.prefix 的站点上必然报 1146 Table doesn't exist。
     */
    public function prepare($sql)
    {
        return $this->pdo->prepare(self::applyPrefix($sql));
    }

    /** 判断某表是否已存在（按当前前缀） */
    public function hasTable($name)
    {
        $st = $this->pdo->prepare(
            "SELECT COUNT(*) c FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?"
        );
        $st->execute([self::tableName($name)]);
        $row = $st->fetch();
        return intval($row['c']) > 0;
    }

    /** 判断某索引是否已存在（MySQL 5.7 无 CREATE INDEX IF NOT EXISTS，需先查） */
    public function hasIndex($table, $index)
    {
        $st = $this->pdo->prepare(
            "SELECT COUNT(*) c FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?"
        );
        $st->execute([self::tableName($table), $index]);
        $row = $st->fetch();
        return intval($row['c']) > 0;
    }

    public function query($sql, $params = [])
    {
        $st = $this->pdo->prepare(self::applyPrefix($sql));
        $st->execute($params);
        return $st->fetchAll();
    }

    public function one($sql, $params = [])
    {
        $st = $this->pdo->prepare(self::applyPrefix($sql));
        $st->execute($params);
        $r = $st->fetch();
        return $r === false ? null : $r;
    }

    public function val($sql, $params = [])
    {
        $r = $this->one($sql, $params);
        return $r === null ? null : reset($r);
    }

    /** @return PDOStatement 便于调用方 rowCount() */
    public function exec($sql, $params = [])
    {
        $st = $this->pdo->prepare(self::applyPrefix($sql));
        $st->execute($params);
        return $st;
    }

    public function insert($table, $data)
    {
        $cols = array_keys($data);
        $ph   = implode(',', array_fill(0, count($cols), '?'));
        $sql  = sprintf('INSERT INTO %s (%s) VALUES (%s)', $this->tbl($table), implode(',', $cols), $ph);
        $st   = $this->pdo->prepare($sql);
        $st->execute(array_values($data));
        return intval($this->pdo->lastInsertId());
    }

    public function update($table, $data, $where, $whereParams = [])
    {
        $sets = [];
        $vals = [];
        foreach ($data as $k => $v) {
            $sets[] = "$k = ?";
            $vals[] = $v;
        }
        $sql = sprintf('UPDATE %s SET %s WHERE %s', $this->tbl($table), implode(',', $sets), $where);
        $st  = $this->pdo->prepare($sql);
        return $st->execute(array_merge($vals, $whereParams));
    }

    public function delete($table, $where, $params = [])
    {
        $st = $this->pdo->prepare("DELETE FROM " . $this->tbl($table) . " WHERE $where");
        return $st->execute($params);
    }

    public function getErrors()
    {
        return $this->log;
    }
}
