# SQLite → MySQL 迁移说明

> 版本：UIC 1.0.0 ｜ 目标：MySQL 5.7+ / MariaDB 10.2+ ｜ PHP 7.0+（需 `pdo_mysql`）
>
> 迁移原则：**通信层对外接口零变更**。UCenter 协议、API 路径、请求/返回字段、SDK 全部保持原样，只换存储引擎与安装方式。

---

## 一、改造后的完整代码清单

### 1. 新增文件

| 文件 | 行数 | 说明 |
| --- | --- | --- |
| `public/install.php` | ~500 | 安装向导：环境检查 → 数据库配置 → 连接检测 → 建表 → 建管理员 → 写安装锁；含一键删除自身 |
| `data/db_cred.php` | 由安装生成 | 数据库凭据（host/port/dbname/user/pass/charset/prefix），不入版本库，chmod 600 |
| `data/install.lock` | 由安装生成 | 安装锁，存在时拒绝重复安装 |

### 2. 重写/修改的文件

| 文件 | 改动要点 |
| --- | --- |
| `app/Core/Database.php` | 整体重写为 MySQL PDO：DSN、`SET NAMES`、sql_mode 收敛、事务、表前缀注入、友好错误码；新增 `prepare()` / `hasTable()` / `hasIndex()` / `prefix()` / `table()` / `applyPrefixFor()` / `tableName()` |
| `config/config.php` | `db` 段改为 MySQL 参数并新增 `prefix`；支持 `data/db_cred.php` 覆盖 |
| `data/schema.sql` | 全部改为 MySQL 语法（`AUTO_INCREMENT` / `VARCHAR` 主键 / `ENGINE=InnoDB` / 无 `CREATE INDEX IF NOT EXISTS`） |
| `app/Core/Config.php` | `set()` 改为 `UPDATE → INSERT IGNORE`；改用 `$db->prepare()` 走前缀注入 |
| `app/Core/UCenter/MockDriver.php` | 去掉独立 SQLite 文件，模拟用户表并入主库 `uc_members`；全部改用 `Database` 封装 |
| `app/Core/UCenter/NotifyHandler.php` | `ensureTable()` 建索引前先查 `information_schema`，避免 MySQL 5.7 刷 1061 日志 |
| `app/Service/UserService.php` | `failLogin()` 改为 `UPDATE → INSERT IGNORE`，并改用 `$db->prepare()` |
| `tools/selftest.php` | 表存在性检查按 `Database::prefix()` 比对 |
| `docs/DATABASE.md` / `docs/UCENTER.md` | 同步为 MySQL 描述 |

### 3. 关键源码（`app/Core/Database.php` 核心片段）

```php
class Database
{
    /** 需要加前缀的业务表名白名单（避免误替换普通标识符） */
    const TABLES = [
        'settings', 'apps', 'sessions', 'tickets', 'tokens', 'user_profiles',
        'verify_codes', 'captcha_tokens', 'logs', 'admins', 'uc_calls',
        'uc_notices', 'login_fail', 'uc_members',
    ];

    private static function loadConfig()
    {
        $cfg = require dirname(__DIR__, 2) . '/config/config.php';
        $db  = isset($cfg['db']) && is_array($cfg['db']) ? $cfg['db'] : [];
        return [
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
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $this->cfg['host'], $this->cfg['port'], $this->cfg['dbname'], $this->cfg['charset']);
        try {
            $this->pdo = new PDO($dsn, $this->cfg['user'], $this->cfg['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,   // 真预处理，不手工转义
                PDO::ATTR_TIMEOUT            => 8,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException(self::friendlyError($e, $this->cfg));
        }
        $this->pdo->exec("SET NAMES " . $this->cfg['charset']);
        // 只放宽会影响旧写法的模式，其余（如 NO_ZERO_DATE）保留
        $mode  = (string)$this->pdo->query("SELECT @@SESSION.sql_mode")->fetchColumn();
        $drop  = ['ONLY_FULL_GROUP_BY', 'STRICT_TRANS_TABLES', 'STRICT_ALL_TABLES'];
        $parts = array_values(array_filter(array_diff(explode(',', $mode), $drop)));
        $this->pdo->exec("SET SESSION sql_mode = '" . str_replace("'", '', implode(',', $parts)) . "'");
        if ($this->isEmpty()) {
            $this->migrate();
        }
    }

    /** 表前缀注入：只在 FROM/JOIN/INTO/UPDATE/TABLE/ON 后替换白名单表名 */
    public static function applyPrefixFor($sql, $prefix)
    {
        $pre = trim((string)$prefix);
        if ($pre === '') {
            return $sql;
        }
        $tables  = self::TABLES;
        $pattern = '/\b(?:TABLE\s+IF\s+NOT\s+EXISTS|FROM|JOIN|INTO|UPDATE|TABLE|ON)\s+`?([A-Za-z_][A-Za-z0-9_]*)`?/i';
        return preg_replace_callback($pattern, function ($m) use ($pre, $tables) {
            $t = $m[1];
            if (!in_array($t, $tables, true)) {
                return $m[0];
            }
            // 用字符串拼接而非替换串：前缀含 $ 或 \ 会被当成反向引用
            $pos = strlen($m[0]) - strlen($t);
            return substr($m[0], 0, $pos) . '`' . $pre . $t . '`';
        }, $sql);
    }

    /** 是否已建表：只精确判断核心表，不统计全库（共用库场景才不会误判） */
    private function isEmpty()
    {
        $st = $this->pdo->prepare(
            "SELECT COUNT(*) c FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?"
        );
        $st->execute([self::prefix() . 'settings']);
        return intval($st->fetch()['c']) === 0;
    }

    public function beginTransaction() { /* ... */ }
    public function commit()           { /* ... */ }
    public function rollBack()         { /* ... */ }
    public function transaction(callable $fn) { /* 闭包事务，异常自动回滚 */ }

    public function getPdo()        { return $this->pdo; }
    public function prepare($sql)   { return $this->pdo->prepare(self::applyPrefix($sql)); }
    public function query($sql, $params = [])  { /* applyPrefix + fetchAll */ }
    public function one($sql, $params = [])    { /* applyPrefix + fetch */ }
    public function val($sql, $params = [])    { /* one + reset */ }
    public function exec($sql, $params = [])   { /* applyPrefix，返回 PDOStatement */ }
    public function insert($table, $data)      { /* 返回 lastInsertId */ }
    public function update($table, $data, $where, $whereParams = []) { /* ... */ }
    public function delete($table, $where, $params = [])             { /* ... */ }
}
```

> ⚠️ **调用契约红线**：业务代码凡是要 `PDOStatement`（`rowCount()` / 逐行 `fetch()`），
> 必须用 `$db->prepare()`，**不能**用 `$db->getPdo()->prepare()`——后者绕过前缀注入，
> 在设置了 `db.prefix` 的站点上必然报 `1146 Table doesn't exist`。

---

## 二、需同步调整的配置项

### 1. `config/config.php`（可进版本库的默认值）

| 配置项 | SQLite 版 | MySQL 版 | 说明 |
| --- | --- | --- | --- |
| `db.driver` | `sqlite` | **删除** | MySQL 版固定 PDO MySQL，不再有驱动概念 |
| `db.path` / `db.file` | `data/auth.db` | **删除** | 文件型参数不再存在 |
| `db.host` | — | **新增** `127.0.0.1` | 数据库主机 |
| `db.port` | — | **新增** `3306` | 端口 |
| `db.dbname` | — | **新增** `uic_auth` | 库名（需提前创建或由安装向导自动创建） |
| `db.user` | — | **新增** `uic_app` | 数据库账号 |
| `db.pass` | — | **新增** `''` | 密码；真实值只存 `data/db_cred.php` |
| `db.charset` | — | **新增** `utf8mb4` | 建议保持，兼容 emoji 昵称 |
| `db.prefix` | — | **新增** `''` | 表前缀，多站点共用一库时必填，如 `uic_` |
| `schema_path` | `data/schema.sql` | 保留 | 仍指向 MySQL 版 schema |

### 2. `data/db_cred.php`（新增，安装向导生成，**不入版本库**）

```php
<?php
return [
  'host'    => '127.0.0.1',
  'port'    => 3306,
  'dbname'  => 'uic_auth',
  'user'    => 'uic_app',
  'pass'    => '******',
  'charset' => 'utf8mb4',
  'prefix'  => 'uic_',
];
```

覆盖优先级：`data/db_cred.php` > `config/config.php` 的 `db` 段。

### 3. MySQL 服务端要求

| 项目 | 要求 |
| --- | --- |
| 版本 | MySQL 5.7+ / MariaDB 10.2+（已在 5.7.26 验证） |
| 字符集 | 库/表统一 `utf8mb4` + `utf8mb4_unicode_ci` |
| 存储引擎 | InnoDB |
| sql_mode | 代码自动放宽 `ONLY_FULL_GROUP_BY` / `STRICT_*`，其余保留；**无需手工改全局 sql_mode** |
| 账号权限 | `SELECT, INSERT, UPDATE, DELETE, CREATE, INDEX, ALTER` on `库名.*`（安装期额外需要 `CREATE DATABASE` 或由运维预建库） |
| 时区 | 应用统一用 INT 存 Unix 时间戳，不依赖 MySQL 时区 |
| PHP 扩展 | 必须启用 `pdo_mysql`（`php -m \| grep pdo_mysql`） |

---

## 三、数据库表结构变更点

### 1. 语法层（全表通用）

| 项 | SQLite 写法 | MySQL 写法 | 影响 |
| --- | --- | --- | --- |
| 自增主键 | `INTEGER PRIMARY KEY AUTOINCREMENT` | `INT AUTO_INCREMENT PRIMARY KEY` | `apps.id` / `admins.id` / `logs.id` / `uc_calls.id` / `uc_members.uid` |
| 文本主键 | `TEXT PRIMARY KEY` | `VARCHAR(n) PRIMARY KEY` | MySQL 的 `TEXT` 不能做主键索引长度，必须给长度：`settings.s_key VARCHAR(191)`、`sessions.sid VARCHAR(64)`、`tickets.ticket VARCHAR(64)`、`tokens.token VARCHAR(64)` |
| 日期时间 | `DATETIME` / `NOW()` / `CURRENT_TIMESTAMP` | **全部改 `INT` 存 Unix 时间戳** | 规避时区与 `NO_ZERO_DATE` 问题；PHP 侧 `time()` 写入 |
| 默认值 | `DEFAULT 'text'` 混用 | 全部显式 `NOT NULL DEFAULT ...` | 避免严格模式插入报错 |
| 建表幂等 | `CREATE TABLE IF NOT EXISTS`（支持） | 支持；**索引不支持 `IF NOT EXISTS`** | 索引重复由 `migrate()` 捕获 `Duplicate key name` 忽略，或先查 `information_schema.statistics` |
| upsert | `INSERT OR REPLACE` / `INSERT OR IGNORE` | `INSERT IGNORE`（保留首条）／`UPDATE →  INSERT IGNORE` 两段式 | `Config::set()`、`failLogin()` 已改两段式 |
| 字符串拼接 | `\|\|` 运算符 | 改用 PHP 侧拼接或 `CONCAT()` | 代码中已无 `\|\|` 拼接 |
| 引号 | 双引号标识符 `"col"` | 反引号 `` `col` `` | 全部统一反引号 |
| 布尔/整数 | 无强类型 | `INT NOT NULL DEFAULT 0/1` | 状态位统一 |
| 引擎/字符集 | 无 | 每张表追加 `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci` | — |

### 2. 表级变更

| 表名 | SQLite 版 | MySQL 版 | 变更点 |
| --- | --- | --- | --- |
| `settings` | `s_key TEXT PRIMARY KEY`<br>`s_group TEXT`<br>`remark TEXT` | `s_key VARCHAR(191) PRIMARY KEY`<br>`s_group VARCHAR(32) NOT NULL DEFAULT 'system'`<br>`remark VARCHAR(255) DEFAULT NULL` | 主键改 VARCHAR；枚举型短字段收窄；新增 `idx_settings_group` |
| `apps` | `id INTEGER PK AUTOINCREMENT`<br>`app_id TEXT UNIQUE`<br>`home_url TEXT` | `id INT AUTO_INCREMENT PK`<br>`app_id VARCHAR(64) NOT NULL UNIQUE`<br>`home_url VARCHAR(255)` | 自增改写；新增 `ip_whitelist TEXT`、`sort_order INT`；索引 `idx_apps_status` |
| `sessions` | `sid TEXT PRIMARY KEY` | `sid VARCHAR(64) PRIMARY KEY` | 主键收窄为 VARCHAR |
| `tickets` | `ticket TEXT PRIMARY KEY` | `ticket VARCHAR(64) PRIMARY KEY` | 同上 |
| `tokens` | `token TEXT PRIMARY KEY` | `token VARCHAR(64) PRIMARY KEY` | 同上 |
| `user_profiles` | `uid INTEGER PRIMARY KEY`<br>`nickname TEXT` | `uid INT PRIMARY KEY`<br>`nickname VARCHAR(64)` | `email VARCHAR(191)`（与 `uc_members` 一致，便于联合索引） |
| `verify_codes` | 同结构 | `VARCHAR` 化 + `INT` 时间戳 | — |
| `captcha_tokens` | 同结构 | `VARCHAR` 化 | — |
| `logs` | `id INTEGER PK AUTOINCREMENT`<br>`msg TEXT` | `id INT AUTO_INCREMENT PK`<br>`msg VARCHAR(1000)` | 新增常用查询索引（type / uid / created_at） |
| `admins` | `id INTEGER PK AUTOINCREMENT`<br>`username TEXT UNIQUE` | `id INT AUTO_INCREMENT PK`<br>`username VARCHAR(64) NOT NULL UNIQUE`<br>`password_hash VARCHAR(255)` | `VARCHAR(255)` 预留 `PASSWORD_BCRYPT`/Argon2 哈希长度 |
| `uc_calls` | 同结构 | `VARCHAR` 化 + `INT` 时间戳 | — |
| `uc_notices` | 原为运行期动态建表 | **纳入 `schema.sql` 正式建表** | 新增 `idx_ucnotice_action` / `idx_ucnotice_time` |
| `login_fail` | `account TEXT PRIMARY KEY` | `account VARCHAR(191) PRIMARY KEY` | 主键收窄 |
| `uc_members` | 独立文件 `data/ucenter_mock.db` | **并入主库**，表名 `<prefix>uc_members` | Mock 驱动不再使用独立 SQLite；`email VARCHAR(191)` 唯一索引 |

**新增表**：`uc_notices`（原运行期动态创建，现随 schema 一起建）、`captcha_tokens`（若原版没有）。

**移除**：`data/auth.db`、`data/ucenter_mock.db` 两个 SQLite 文件及所有 `sqlite3`/`pdo_sqlite` 依赖（生产环境可不装 `pdo_sqlite`）。

---

## 四、对外接口兼容性（未变更）

| 层 | 说明 |
| --- | --- |
| UCenter 客户端协议 | `authcode` 加解密、`uc_user_*` 系列调用、返回码语义完全一致 |
| UCenter 通知接收端 | `public/api/uc.php` 路径、`code` 参数、回执 `'1'`、积分/标签序列化格式不变 |
| 业务 API | `public/api/index.php` 全部路由、参数名、JSON 字段不变 |
| SSO | `ticket` / `token` 签发、消费、userinfo 字段不变 |
| SDK | `public/sdk/` 无需改动 |
| 后台 | `public/admin.php` 页面与操作不变 |

---

## 五、上线步骤

1. 服务器建库建账号：
   ```sql
   CREATE DATABASE uic_auth DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'uic_app'@'localhost' IDENTIFIED BY '强密码';
   GRANT SELECT,INSERT,UPDATE,DELETE,CREATE,INDEX,ALTER ON uic_auth.* TO 'uic_app'@'localhost';
   FLUSH PRIVILEGES;
   ```
2. 上传代码（确保 `data/`、`config/` 可写）。
3. 浏览器访问 `http://域名/install.php`，按向导填写主机/端口/库名/前缀/账号/密码 → 测试连接 → 开始安装。
4. 安装完成**立即删除** `public/install.php`（页面提供一键删除按钮）。
5. 确认 `data/db_cred.php` 权限为 600、`data/install.lock` 已生成。
6. 校验：登录后台 → 「UCenter 配置」测试连接 → 通知设置逐项测试 → 观察 `uc_notices` 表有记录。

### 回滚

删除 `data/install.lock` 与 `data/db_cred.php` 即可重新进入安装向导。旧 SQLite 数据可自行用 `sqlite3 .dump` 转 SQL 后按上表改写导入（注意 `AUTOINCREMENT`→`AUTO_INCREMENT`、时间字段转 `INT` 时间戳）。

---

## 六、本地验证结果（MySQL 5.7.26）

| 场景 | 主自检 `tools/selftest.php` | 通知自检 `tools/ucnotify_selftest.php` |
| --- | --- | --- |
| 前缀 `uic_` | 66 / 66 通过 | 34 / 34 通过 |
| 前缀为空 | 66 / 66 通过 | 34 / 34 通过 |
| 全新库安装（`fx_` 前缀） | 39 条建表语句、管理员创建、凭据+锁写入成功 | — |
| 重复安装 | 被 `install.lock` 拒绝 | — |
| HTTP 冒烟 | `index.php` 200 / `install.php` 200 / `admin.php` 302 / `api/index.php` 200 / `api/uc.php` 200 | — |
