# 更新日志（Changelog）

统一身份认证中心（UIC）版本迭代记录。格式参考 [Keep a Changelog](https://keepachangelog.com/)，版本号遵循语义化版本（SemVer）。

---

## [v1.1.0] — 企业级能力增强（2026-10-13 ~ 2026-10-15）

> 本期聚焦「人机验证体系升级、通行密钥、会员中心产品展示、管理员企业级能力、多语言 SDK 打包」五大方向，并在多处做了响应式与文档同步。

### ✨ 新增功能

- **人机验证体系（CAPTCHA）全面升级**
  - 新增验证方式：`slide`（滑块拼图）、`click`（文字点选）、`icon`（图标选择）、`passive`（无感验证）、`digits`、`mixed`，由后台 `captcha_style` 统一切换。
  - 统一前端模块 `UicCaptcha`（`public/assets/captcha.js`），内置 `.verify-toast` 浮层反馈（成功绿/失败红 + 卡片晃动）。
  - 验证码令牌约束：单次有效、5 分钟、最多 5 次尝试；`captcha_verify` 预检不消耗 token，登录网关终检消耗。
  - 点选/图标答案存 `sha256(answer+salt)`；拼图坐标容差 10px。
  - 详见 `docs/CAPTCHA_UPGRADE.md`。

- **无感验证风险引擎（RiskEngine）**
  - 新增 `app/Model/RiskEngine.php`：前端上报原始轨迹 + 环境指纹，服务端重算特征（时长/点数/抖动/方向熵/方向变化/速度变异系数）。
  - 行为/环境/服务端三维信号按可配置权重加权，得 0–100 风险分；按 `passive_score_pass/challenge/block` 或强度档位分级。
  - IP/CIDR 白名单 + HMAC 签名信任 Cookie（`uic_passive_trust`）实现跨页无感放行；静止用户降级为 challenge 不误杀。
  - 后台「安全与验证 → 无感验证」全可配。详见 `docs/PASSIVE_RISK_ENGINE.md`。

- **通行密钥 / WebAuthn（Passkey）**
  - 新增 `PasskeyService` → `PasskeyModel`，表 `uic_user_passkeys` / `uic_webauthn_challenges`。
  - 注册/认证/列表/重命名/删除全链路；安全中心一键添加，登录页支持免密登录。
  - 域约束：`PasskeyService::domainIssue()/domainUsable()` 统一判定（IP 非法 / 非安全上下文 / 域不匹配等），前端优雅降级（禁用按钮 + 中文提示）。
  - 新增 `m=passkey` 共 8 个接口，`passkey_status` 返回 `domain_ok/domain_issue/domain_reason/domain_hint`。
  - 完整文档见 `docs/PASSKEY.md`。

- **会员中心产品展示（Member Products）**
  - 新增 `uic_member_products` 表 + `MemberProductModel`（懒迁移会把历史无前缀表 RENAME 到当前前缀）。
  - 前台会员中心彩色磁贴展示（12 种内置图标配色）；后台「系统 → 产品展示」可视化增删改查（RBAC `manage:system`）。
  - 新增 `m=admin_product` 五个接口（list/create/update/delete/toggle）。详见 `docs/MEMBER_PRODUCTS.md`。

- **管理员企业级能力（m=admin）**
  - 会话管理：`session_list` / `session_kill` / `session_kick_user`（强制单/全下线）。
  - 管理员 TOTP 二步验证：`totp_status` / `totp_setup` / `totp_enable` / `totp_disable`。
  - 安全审计中心：`audit_stats`（风险概览 + 告警分布 + 近 7 天趋势 + 明细）。
  - UCenter 通知接收：`uc_notify` / `uc_notify_save` / `uc_notify_test`（闭环自测）/ `uc_notify_clear`。
  - 第三方登录：`oauth_save`（11 平台）/ `oauth_save_global`（自动注册策略）。
  - 账号注销审核：`deletion_list` / `deletion_review`。
  - 站点信息：`site_save`；用户永久删除：`user_delete`（同步注销 UCenter + 清理本地数据）。
  - SDK 压缩包管理：`sdkpkg_list` / `sdkpkg_upload` / `sdkpkg_delete`。
  - RBAC 权限门禁：后台关键操作统一经 `manage:*` / `view:*` 权限点校验。

- **管理员找回密码**
  - 新增 `App/Service/AdminResetService.php` + 接口 `admin_findpwd_send` / `admin_findpwd_reset`。
  - 启用需「开关开启 + 邮件服务可用 + 该管理员已填邮箱」；账号+邮箱双重匹配防枚举、频控、口令强度与历史防重用、验证码用后即焚。
  - 重置成功强制其他设备后台下线。详见 `docs/ADMIN_FINDPWD.md`。

- **会员中心响应式改版**
  - 顶部通栏重做：品牌 + 分组导航（浏览与文档 / 我的账号）+ 帮助反馈 + 用户胶囊 + 场景化主操作 + 退出图标；`≤1000px` 折叠为 `<details>` 汉堡抽屉。
  - 主操作场景化：有后台会话 →「进入控制台」；profile →「编辑资料」；security →「修改密码」。
  - 断点：1000 头部折叠 / 900 单栏 / 640 紧凑 / 420 极窄 / 横屏压缩。

- **登录页动态星空**
  - 新增 `public/assets/auth-bg.js`（canvas 星空 + 流星 + 视差）+ `app.css` `.auth-bg__*`；逐星独立相位，`reduced-motion` 与页签隐藏自动静止；诊断钩子 `window.__authBg`。

- **设置中心重构**
  - 三级大纲唯一来源 `app/Core/SettingsSchema.php`（9 模块 97 项）；新增设置项四步法（大纲 → config 默认 → schema 种子 → 迁移）。

- **多语言 SDK 与开放平台打包**
  - 提供 PHP / Java / Go / Python / JavaScript / C 六语言 SDK，统一签名鉴权协议。
  - 开放平台压缩包：OpenAPI 源码 + SDK 包 + 开发文档（docx）；SDK 包提供各语言示例工程。

### 🐛 修复

- 数据库表前缀白名单一致性：新增业务表须同时加入 `Database::TABLES`、建表语句用 `IF NOT EXISTS`、`data/schema.sql` 同步，避免 `1050 already exists` 致命错。
- 视图内禁止定义全局函数（同一进程渲染两次布局即 fatal redeclare）；渲染逻辑改为模型静态方法（如 `MemberProductModel::iconSvg()`）。
- `Database` 契约无 `all()` / `lastInsertId()`：多行取值用 `query()`，自增 id 用 `insert()` 返回值。
- 会员中心布局：修复 1001–1060px 头部横向溢出（`.mh-brand{flex:0 1 auto;min-width:0}` + `.mh-nav{flex:none}`）。
- 无感验证失败返回哨兵串 `__passive_fail__` / `__passive_block__`，登录网关翻译为用户可读提示。

### 🔒 安全加固与上线就绪（上线评估整改）

- **UCenter 默认驱动 `mock` → `http`**：`config/config.php` 与 `data/schema.sql` 种子同步改为 `http`，
  `uc_url` / `uc_appid` 改为生产端点。**mock 是本地模拟驱动，上线若仍为 mock，注册/登录不会写入真实 UCenter。**
- **`pwd_min_length` 6 → 8**：`config/config.php`、`data/schema.sql` 种子与运行库同步提升（安全基线）。
- **新增 `.gitignore`**：排除 `data/db_cred.php`（数据库口令）、`config/config.php`、`data/install.lock`、
  `data/*.bak*`、`*.zip` / `*.docx` 等敏感与产物文件，杜绝一次 `git add -A` 泄露凭据。
- **配置支持环境变量注入**（优先级：环境变量 > `settings` 表 > `config/config.php`）：
  规则 `UIC_` + 配置键大写（点号转下划线），如 `uc_key → UIC_UC_KEY`、`uc_driver → UIC_UC_DRIVER`。
  生产可用其注入 UCenter 端点/密钥与短信密钥，无需硬编码、无需落库。新增 `Config::fromEnv()` 便于运维自检。
- **全局异常兜底**（`app/bootstrap.php`）：强制 `display_errors=0`，注册 `set_exception_handler` /
  `set_error_handler` / `register_shutdown_function`（含 E_ERROR/E_PARSE 致命错误兜底），
  统一记 `logs(type=system)`，用户侧仅返回通用错误页，不再泄露路径 / 堆栈 / SQL。
- **会话 Cookie**：补充 `SameSite=Lax`，并识别 `X-Forwarded-Proto`，修复反向代理下 `Secure` 标记缺失。
- **`user_logout` 越权修复**：传入非当前会话 `sid` 时校验归属，防止销毁他人会话（IDOR）。
- **移除弱口令管理员种子**：`Database::migrate()` 不再自动播种 `admin/admin123`，管理员仅由安装向导按强口令策略创建。
- **健康检查强化**（`public/health.php`）：新增 `uc_driver` 与 `warnings` 字段，`uc_driver=mock` 直接判定
  `503 degraded`，作为"模拟驱动误上生产"的发布闸门。
- **新增运维清理工具 `tools/log_rotate.php`**：清理过期日志 / `uc_calls` / Ticket / Token / 验证码 / 会话，
  支持 `--days` `--keep-uc` `--dry-run`；并修复 `UserSession::cleanExpired()` 无返回值导致统计恒为 0 的缺陷。
- **新增 `docs/DEPLOYMENT.md`**：部署步骤、生产配置核对、上线前检查清单、代码与数据库回滚方案、
  备份策略、监控告警、日志轮转计划任务、故障速查表。
- **补齐 Nginx 配置**：`nginx.htaccess` 与 `public/nginx.htaccess` 此前为 0 字节空文件，现补全
  （docroot 指向 `public/`、敏感目录 deny、`X-Forwarded-Proto` 透传、install.php 封禁、health 探测）。
- 版本号 `UIC_VERSION` 1.0.0 → 1.1.0，与 CHANGELOG 对齐。

### ⚠️ 不兼容变更 / 注意事项

- 通行密钥要求 RP ID 为合法域名，**IP 访问（含 127.0.0.1 / 局域网 IP）一律非法**，且需安全上下文（https 或 `http://localhost`）。本地开发请走 `http://localhost` 而非 IP。
- CORS 由通配符 `*` 改为仅 `cors_origins` 白名单回显来源并允许携带凭据，避免任意站点读取 Cookie。
- 滑块背景图使用独立类名 `verify-slide-bg`，避免被 `.verify-image{height:46px}` 压扁。

---

## [v1.0.0] — 基线版本

- 统一注册/登录/找回密码（用户名/邮箱/手机三类型）。
- SSO 双通道（ticket + token）、应用（SP）管理、UCenter 对接（http/mock 双驱动）。
- 图形验证码（slide/digits/mixed）、短信/邮件可插拔网关、第三方登录（OAuth 框架）。
- 会话管理、账号安全（失败锁定/TOTP/注销申请/回收站）、审计日志、安全中心（IP 策略/CORS/维护模式）。
- 多语言 SDK（PHP/Java/Go/Python/JS/C）与开放平台文档。
