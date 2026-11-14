# 数据库设计说明（MySQL）

统一身份认证中心（UIC）采用**分层存储**原则：

- **核心账号数据（UID / 用户名 / 密码 / 邮箱 / 手机号 / 基础资料 / 账号状态）** 一律保存在 **UCenter**，本系统不重复建用户表，仅通过 UCenter 客户端协议读写。
- **本地 MySQL** 仅保存非核心身份数据：系统配置、应用接入配置、SSO 票据与令牌、本地会话、扩展资料、验证码、日志、UCenter 通信记录、管理员、登录限流。

> 这样做的好处：账号主数据单一来源（UCenter），认证中心可被多套系统共用；本地库只承担「通信层状态」，多站点可共用同一个 MySQL 实例（靠表前缀隔离）。

---

## 存储引擎

- 引擎：MySQL 5.7+ / MariaDB 10.2+，全部 `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`。
- 连接：PDO `pdo_mysql`，DSN 为 `mysql:host=...;port=...;dbname=...;charset=...`。
- 初始化脚本：`data/schema.sql`，由 `app/Core/Database.php::migrate()` 在首次访问时自动执行（`CREATE TABLE IF NOT EXISTS`，幂等）。
- 安装向导：`public/install.php`（环境检查 → 数据库配置 → 连接检测 → 建表 → 建管理员 → 写锁）。
- 配置表 `settings` 之外的数据均为业务运行态数据；`settings` 同时接受 `config/config.php` 的默认值覆盖（运行时以数据库为准）。

### 连接配置优先级

```
config/config.php 的 'db' 段（默认值，可进版本库）
        ↓ 被覆盖
data/db_cred.php（install.php 生成的真实凭据，不入版本库，权限 600）
```

---

## 数据表清单（14 张建表 + Mock 用户表）

| 表名 | 作用 | 是否核心身份 |
| --- | --- | --- |
| `settings` | 系统配置键值表（站点、SSO 时长、邮件/短信、UCenter 连接等） | 否 |
| `apps` | 接入应用（站点）配置：AppID / AppSecret / 回调地址 / SSO 开关 | 否 |
| `sessions` | 用户登录会话（Cookie 只存 sid，敏感态落库，支持多端/踢下线） | 否 |
| `tickets` | SSO 一次性票据（限时、绑定 app，用后即焚） | 否 |
| `tokens` | SSO 长期令牌（服务端凭 AppSecret 换取，可撤销） | 否 |
| `user_profiles` | 用户**扩展资料**（昵称/性别/生日/签名/头像/本地邮箱手机覆盖） | 否（扩展） |
| `verify_codes` | 验证码（注册/找回密码/改绑，含发送通道与失效时间） | 否 |
| `captcha_tokens` | 图形/行为验证码令牌 | 否 |
| `logs` | 全量操作日志（登录/退出/注册/改密/找回/改资料/SSO/API/UC/后台/应用） | 否 |
| `admins` | 认证中心**自身管理员**（独立于 UCenter 账号体系） | 否 |
| `uc_calls` | UCenter 通信原始记录（排错用：请求参数、响应、耗时、状态） | 否 |
| `uc_notices` | UCenter → 本应用 的通知接收记录（开关回执、耗时、状态） | 否 |
| `login_fail` | 登录失败限流（账号维度失败次数、锁定时长） | 否 |
| `uc_members` | **仅 Mock 驱动**使用的模拟 UCenter 用户表（生产环境应切 `http` 驱动） | 开发用 |

---

## 关键表结构（MySQL）

### settings（系统配置）
```sql
CREATE TABLE IF NOT EXISTS settings (
    s_key       VARCHAR(191) PRIMARY KEY,   -- 配置键
    s_value     TEXT,                       -- 配置值(JSON / 字符串)
    s_group     VARCHAR(32) NOT NULL DEFAULT 'system',
    remark      VARCHAR(255) DEFAULT NULL,
    updated_at  INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_settings_group ON settings(s_group);
```
常用键：`site_name`、`register_enabled`、`login_fail_limit`、`session_ttl`、`ticket_ttl`、`token_ttl`、`mail_*`、`sms_*`、`uc_driver`、`uc_url`、`uc_key`、`uc_appid` 等（完整清单见 `data/schema.sql` 种子）。

### apps（应用接入）
```sql
CREATE TABLE IF NOT EXISTS apps (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    app_id        VARCHAR(64) NOT NULL UNIQUE,  -- AppID,如 'site_a'
    app_secret    VARCHAR(64) NOT NULL,         -- AppSecret
    name          VARCHAR(191) NOT NULL,
    description   VARCHAR(500) DEFAULT '',
    home_url      VARCHAR(255) DEFAULT '',
    callbacks     TEXT,                         -- JSON 数组,回调地址白名单
    ip_whitelist  TEXT,                         -- JSON 数组,服务端出口IP白名单
    status        INT NOT NULL DEFAULT 1,       -- 1启用 0禁用
    sso_enabled   INT NOT NULL DEFAULT 1,       -- 是否允许参与单点登录
    sort_order    INT NOT NULL DEFAULT 0,
    created_at    INT NOT NULL DEFAULT 0,
    updated_at    INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_apps_status ON apps(status);
```

### sessions（会话）
```sql
CREATE TABLE IF NOT EXISTS sessions (
    sid         VARCHAR(64) PRIMARY KEY,
    uid         INT NOT NULL,              -- 来自 UCenter 的 UID
    device_id   VARCHAR(64) DEFAULT '',
    device_label VARCHAR(64) DEFAULT '',
    ip          VARCHAR(64) DEFAULT '',
    ua          VARCHAR(500) DEFAULT '',
    created_at  INT NOT NULL DEFAULT 0,
    expires_at  INT NOT NULL DEFAULT 0,
    revoked     INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### tickets / tokens（SSO）
- `tickets`：`ticket` VARCHAR 主键 / `uid` / `app_id` / `expires_at` / `used`。
- `tokens`：`token` VARCHAR 主键 / `uid` / `app_id` / `expires_at` / `revoked` / `issued_at`。

### user_profiles（扩展资料，非核心）
```sql
CREATE TABLE IF NOT EXISTS user_profiles (
    uid        INT PRIMARY KEY,   -- 与 UCenter UID 对应
    nickname   VARCHAR(64) DEFAULT '',
    gender     INT NOT NULL DEFAULT 0,
    birthday   VARCHAR(32) DEFAULT '',
    bio        VARCHAR(500) DEFAULT '',
    avatar     VARCHAR(500) DEFAULT '',
    email      VARCHAR(191) DEFAULT '',   -- 本地覆盖（可选）
    mobile     VARCHAR(32) DEFAULT '',    -- 本地覆盖（可选）
    synced_at  INT NOT NULL DEFAULT 0,
    updated_at INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### admins（认证中心管理员）
```sql
CREATE TABLE IF NOT EXISTS admins (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(64) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    realname      VARCHAR(64) DEFAULT '',
    role          VARCHAR(32) NOT NULL DEFAULT 'admin', -- super_admin / admin / operator / auditor
    permissions   TEXT,                                 -- JSON
    status        INT NOT NULL DEFAULT 1,
    last_login_at INT NOT NULL DEFAULT 0,
    last_login_ip VARCHAR(64) DEFAULT '',
    created_at    INT NOT NULL DEFAULT 0,
    updated_at    INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```
> ID=1 为系统超级管理员，代码中禁止禁用/删除。

---

## 数据分层小结

```
                ┌──────────────────┐
   用户资料 ───▶ │     UCenter      │  UID/用户名/密码/邮箱/手机/状态（唯一来源）
                └──────────────────┘
                        │ 协议读写（authcode + 序列化/XML）
                ┌──────────────────┐
   扩展资料 ───▶ │  user_profiles   │  昵称/头像/签名...
                ├──────────────────┤
   SSO/应用/会话/日志/配置 ─▶ │  MySQL（前缀可配）│
                └──────────────────┘
```

核心账号永不在本地落库，确保接入 UCenter 后各系统账号一致、可统一治理。
