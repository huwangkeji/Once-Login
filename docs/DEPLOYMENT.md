# 部署与运维手册（生产上线必读）

> 面向运维/部署人员。覆盖：首次部署、生产配置、上线检查、回滚、备份、监控、日志轮转、故障处理。
> 配套文档：`README.md`（功能与配置说明）、`docs/MYSQL_MIGRATION.md`（数据库迁移）、`docs/UCENTER.md`（UCenter 对接）。

---

## 1. 部署架构

```
站点根目录（Nginx root）  →  <项目>/public/     唯一对外暴露目录
禁止对外暴露              →  app/  config/  data/  sdk/  docs/  tools/
```

路由全部为**查询串形式**（`index.php?c=xxx`、`admin.php?c=xxx`），**不需要 URL 重写**。

| 入口 | 地址 | 说明 |
| --- | --- | --- |
| 前台门户 | `/index.php` | 登录/注册/会员中心 |
| 后台管理 | `/admin.php?c=login` | 控制台 |
| API 网关 | `/api/index.php?m=xx&a=xx` | 开放接口 |
| UCenter 回调 | `/api/uc.php` | UCenter 通知接收 |
| OAuth 入口 | `/oauth.php` | 第三方授权 |
| 健康检查 | `/health.php` | **监控探活，无鉴权** |
| 在线文档 | `/index.php?c=docs` | 开发文档 |

---

## 2. 环境要求

- PHP **8.0+**（当前开发/验证环境 8.5.8），需启用：`pdo_mysql`、`mbstring`、`json`、`curl`、`gd`（图形验证码）
- MySQL **5.7+ / 8.0**（utf8mb4）
- Nginx / Apache + PHP-FPM
- **必须 HTTPS**（会话 Cookie 依赖 `Secure`；通行密钥/WebAuthn 强制要求安全上下文）

---

## 3. 首次部署步骤

1. **放置代码**：整包上传，`app/ config/ data/ sdk/ docs/ tools/` 放在站点根目录**之外**；若只能放在同级，务必用 Nginx 拒绝（见第 4 节）。
2. **建库**：创建 MySQL 库（默认 `uic_auth`，utf8mb4）。
3. **配置数据库凭据**（不要写进 `config/config.php`）：新建 `data/db_cred.php`：

   ```php
   <?php
   return [
       'host' => '127.0.0.1', 'port' => 3306,
       'dbname' => 'uic_auth', 'user' => 'uic_app',
       'pass' => '强随机口令', 'charset' => 'utf8mb4', 'prefix' => 'uic_',
   ];
   ```

4. **运行安装向导**：访问 `/install.php`，按向导建表并创建管理员。完成后自动生成 `data/install.lock`，并**建议立即删除 `public/install.php`**。
5. **配置 UCenter**（见第 5 节，最容易出错）。
6. **验证**：访问 `/health.php`，必须返回 `200` 且 `status=ok`。

---

## 4. Nginx 基线配置

直接参考项目根目录 `nginx.htaccess`（完整 server 块）与 `public/nginx.htaccess`（docroot 规则），核心是：

```nginx
root  <项目>/public;                 # 必须指向 public
location ~* ^/(app|config|data|sdk|docs|tools)/ { deny all; return 404; }
location ~* \.(sql|bak|db|sqlite|log|zip|docx|md)$ { deny all; return 404; }
location ~ /\. { deny all; return 404; }          # .git/.env
fastcgi_param HTTP_X_FORWARDED_PROTO $scheme;     # 反代下让后端正确下发 Secure Cookie
```

> 反向代理（Nginx/SLB 终止 TLS）**必须**传递 `X-Forwarded-Proto`，否则后端判定为非 HTTPS，会话 Cookie 不带 `Secure`。

---

## 5. 生产配置（关键，逐项核对）

### 5.1 UCenter —— 最高频故障点

| 项 | 取值 | 说明 |
| --- | --- | --- |
| `uc_driver` | **必须 `http`** | `mock` 是本地模拟驱动，上线若仍为 mock，注册/登录全部写入本地模拟库 |
| `uc_url` | 真实 UCenter 地址 | 形如 `http://ucenter.example.com/uc_server` |
| `uc_appid` | UCenter 中本应用的 AppID | 两端必须一致 |
| `uc_key` | 通信密钥 | 安装时自动生成，**必须与 UCenter 端完全一致**，否则所有通信签名失败 |

生效优先级：**环境变量 > `settings` 表 > `config/config.php`**。生产推荐用环境变量注入，避免硬编码：

```bash
UIC_UC_DRIVER=http
UIC_UC_URL=http://ucenter.example.com/uc_server
UIC_UC_APPID=4
UIC_UC_KEY=<与 UCenter 端一致的密钥>
```

> 环境变量命名规则：`UIC_` + 配置键大写（点号转下划线），如 `uc_key → UIC_UC_KEY`。

### 5.2 其他生产必改项

| 配置键 | 建议值 | 说明 |
| --- | --- | --- |
| `debug` | `0` | 关闭调试；开启会回显验证码等敏感信息 |
| `admin_findpwd_debug` | `0` | 管理员找回验证码回显，**生产严禁开启** |
| `pwd_min_length` | `8` | 密码最小长度（已由 6 调整为 8） |
| `site_icp` | 备案号 | 国内上线需展示 ICP 备案 |
| `site_url` | 固定站点地址 | 避免自动推断在多域名下出错 |
| `captcha_style` | `slide`/`click` 等 | 按需选择；`passive` 无感验证可配 |

### 5.3 短信密钥

不要写入配置文件或数据库，用环境变量注入：
`UIC_SMS_TENCENT_SECRET_ID`、`UIC_SMS_TENCENT_SECRET_KEY`、`UIC_SMS_ALIYUN_ACCESS_KEY_SECRET` 等。

---

## 6. 上线前检查清单（Pre-launch Checklist）

- [ ] `/health.php` 返回 **200**，`checks.uc_driver` 不是 `mock`
- [ ] `uc_url` / `uc_appid` / `uc_key` 与 UCenter 端逐字核对一致；跑一次真实注册验证落库
- [ ] `debug=0`、`admin_findpwd_debug=0`
- [ ] docroot 指向 `public/`；`/app/`、`/config/`、`/data/` 均返回 **404**
- [ ] `public/install.php` 已删除或已限制内网访问
- [ ] 全站 HTTPS；反代已传 `X-Forwarded-Proto`
- [ ] `data/db_cred.php` 已配置且**不在版本库内**（见 `.gitignore`）
- [ ] 数据库已纳入定时备份，并验证过一次**恢复演练**
- [ ] `tools/log_rotate.php` 已加入计划任务
- [ ] `/health.php` 已接入监控告警

---

## 7. 回滚方案

### 7.1 代码回滚（推荐：版本目录 + 软链切换）

```bash
# 发布：整包解压到带版本号的目录，再把站点根目录软链指过去
/data/uic/releases/uic-1.1.0/
/data/uic/current -> releases/uic-1.1.0      # Nginx root 指向 .../current/public

# 回滚：把软链指回上一版本，秒级生效
ln -sfn /data/uic/releases/uic-1.0.0 /data/uic/current
```

回滚后需重启 PHP-FPM（opcache 场景）：`systemctl reload php-fpm`。

### 7.2 数据库回滚

发布前**必须**先备份，回滚时按"结构"与"数据"分别处理：

- **结构变更回滚**：本项目表结构变更均为 `CREATE TABLE IF NOT EXISTS` / 幂等 `ALTER`，通常**向后兼容**，可不回滚结构。
- **数据回滚**：用发布前的备份恢复。

```bash
# 发布前备份（务必加 --single-transaction，避免锁表）
mysqldump -uUSER -p --single-transaction --routines --default-character-set=utf8mb4 uic_auth \
  | gzip > /data/backup/uic_auth_$(date +%F_%H%M).sql.gz

# 回滚恢复
gunzip < /data/backup/uic_auth_YYYY-MM-DD_HHMM.sql.gz | mysql -uUSER -p uic_auth
```

> ⚠️ 恢复会覆盖现有数据。回滚前先导出一份"当前库"快照，避免二次事故。

### 7.3 回滚判定标准

出现以下任一情况立即回滚：
- `/health.php` 连续 3 次非 200
- 登录成功率较发布前下降超过 10%
- 出现 `error` 级日志激增（后台「日志」或 `logs` 表 `level='error'`）

---

## 8. 备份策略

| 对象 | 频率 | 保留 | 方式 |
| --- | --- | --- | --- |
| MySQL 全库 | 每日 | 30 天 | `mysqldump --single-transaction` + gzip |
| 配置文件（`config/config.php`、`data/db_cred.php`） | 变更时 | 永久 | 纳入配置管理，**禁止入库** |
| `public/uploads` 等用户文件 | 每日 | 30 天 | 增量同步 |

**恢复演练**：每季度至少做一次"备份 → 恢复到空库 → 启动验证"的完整演练，未演练的备份等于没有备份。

---

## 9. 监控与告警

### 9.1 健康检查端点

`GET /health.php` —— 无需鉴权、禁止缓存：

```json
{
  "status": "ok",
  "service": "uic-auth-center",
  "version": "1.1.0",
  "checks": { "database": "ok", "ucenter": "ok", "uc_driver": "http" },
  "warnings": []
}
```

- HTTP **200** = 正常；**503** = degraded，`warnings` 字段给出具体原因。
- `uc_driver=mock(unsafe-for-prod)` 会被判定为 degraded —— 这是防止模拟驱动误上生产的闸门。

### 9.2 建议监控项

| 类别 | 指标 | 告警阈值 |
| --- | --- | --- |
| 可用性 | `/health.php` HTTP 码 | 连续 3 次非 200 |
| 业务 | 登录成功率、注册量 | 环比下降 >10% |
| 错误 | `logs` 表 `level='error'` 增量 | 5 分钟内 >10 条 |
| 资源 | MySQL 连接数、磁盘、PHP-FPM 队列 | >80% |
| 安全 | 登录失败锁定数（`login_fail`） | 突增可能是撞库 |

### 9.3 日志

- 业务/审计日志写入 `logs` 表（后台「日志」可查），UCenter 出站调用写入 `uc_calls`。
- **未捕获异常**由全局处理器统一记录（type=`system`），用户侧只看到通用错误页，不泄露路径/堆栈。

---

## 10. 日志轮转与数据清理

`logs` 表会持续增长，必须定时清理：

```bash
# 演练（只统计不删除，首次务必先跑）
php tools/log_rotate.php --dry-run

# 实际执行：日志保留 90 天，UCenter 调用日志保留 30 天
php tools/log_rotate.php --days=90 --keep-uc=30
```

清理范围：过期日志、`uc_calls`、过期 Ticket/Token、过期验证码令牌、过期验证码、过期会话。

**计划任务：**

```bash
# Linux：每天凌晨 3 点
0 3 * * * /usr/bin/php /data/uic/current/tools/log_rotate.php --days=90 >> /var/log/uic-rotate.log 2>&1

# Windows：任务计划程序 → 操作：php.exe 参数：D:\www\uic\tools\log_rotate.php --days=90
```

> 审计/等保要求较高的场景，建议日志保留 180 天。

---

## 11. 常见故障速查

| 现象 | 可能原因 | 处理 |
| --- | --- | --- |
| `/health.php` 报 `uc_driver=mock` | 未切换真实 UCenter | 设置 `uc_driver=http` 并核对 url/appid/key |
| UCenter 通信全部失败 | `uc_key` 两端不一致 | 在 UCenter 端与本系统填入**完全相同**的密钥 |
| 登录成功但 Cookie 未生效 | 反代未传 `X-Forwarded-Proto` | Nginx 增加 `fastcgi_param HTTP_X_FORWARDED_PROTO $scheme;` |
| 通行密钥按钮禁用 | 非 HTTPS 或 IP 访问 | WebAuthn 要求安全上下文；RP ID 不能是 IP，详见 `docs/PASSKEY.md` |
| 安装页提示"系统已安装" | 存在 `data/install.lock` | 正常保护机制；重装需先删除该锁文件 |
| 后台登录报验证码错误 | 验证码 5 分钟失效或已达 5 次尝试上限 | 刷新页面重新获取 |
