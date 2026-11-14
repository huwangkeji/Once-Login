# 统一身份认证中心（UIC）

> **一次登录，处处通行** —— 企业级单点登录（SSO）与统一身份认证平台

---

## 目录

1. [项目简介](#1-项目简介)
2. [目录架构说明](#2-目录架构说明)
3. [数据库字典](#3-数据库字典)
4. [开发者信息](#4-开发者信息)
5. [系统配置与选项说明](#5-系统配置与选项说明)
6. [文档索引与更新日志](#6-文档索引与更新日志)

---

## 1. 项目简介

### 1.1 软件名称

- **中文名称**：统一身份认证中心
- **英文名称**：Unified Identity Center（UIC）
- **版本号**：v1.0.0
- **标语**：一次登录，处处通行（Once Login, Access Everywhere）

### 1.2 项目定位

统一身份认证中心（UIC）是一套**企业级单点登录与账号认证平台**，为多个业务子系统提供统一的用户注册、登录、会话管理、身份认证与第三方授权能力。用户在企业任意一个应用登录后，即可免登录访问其他已接入 UIC 的应用系统，实现"一次登录，处处通行"。

### 1.3 核心功能特性

| 能力域 | 功能说明 |
| --- | --- |
| **用户注册/登录** | 支持开放注册（可后台开关）、用户名/邮箱/手机号多方式登录、图形验证码、忘记密码找回 |
| **单点登录（SSO）** | ticket 模式 + token 模式双通道，支持携带回调地址跨应用跳转登录，会话/票据/Ticket 独立过期策略 |
| **图形验证码** | 六种样式：滑动拼图（slide）/ 文字点选（click）/ 图标选择（icon）/ 无感验证（passive）/ 纯数字（digits）/ 数字+字母（mixed）；令牌式校验，单次有效、5 分钟、最多 5 次尝试、防重放防爆破；无感验证内置风险引擎（行为/环境/服务端三维评分）与无感信任凭证 |
| **短信/邮件验证码** | 可插拔短信网关架构（腾讯云/短信宝/阿里云/华为云），公共限流策略：发送间隔、日上限、IP 上限、验证码有效期 |
| **第三方登录** | 内置 OAuth 扩展框架，支持 11 个平台（微信/QQ/GitHub 等）接入与本地账号绑定，支持自动注册策略 |
| **通行密钥 / WebAuthn** | 基于 WebAuthn 的免密通行密钥：注册/认证/列表/重命名/删除，安全中心一键添加；自动探测域可用性（IP/非 https/域不匹配时优雅降级） |
| **UCenter 对接** | 支持真实 UCenter（http 驱动）与本地模拟（mock 驱动）双模式，双向通知推送、通信签名校验、防重放指纹、通知接收配置与闭环自测 |
| **应用（SP）管理** | 后台可创建/管理接入应用，生成 AppID/AppSecret，配置回调地址、IP 白名单、SSO 开关与排序 |
| **会话管理** | 全量会话查询、强制下线、按用户批量踢下线、记住登录（Remember）与设备信息记录 |
| **账号安全** | 登录失败锁定、密码强度策略（长度/有效期/防重用）、TOTP/二步验证、异地会话提示、账号注销申请与回收站、管理员找回密码（邮箱验证） |
| **会员中心产品展示** | 会员中心前台彩色磁贴展示接入产品（名称/链接/图标/排序/启用），后台可视化增删改查 |
| **审计与日志** | 全量操作日志（登录、注册、应用操作、安全事件等），按类型/级别/用户/时间多维检索；安全审计中心聚合风险概览、告警分布与趋势 |
| **管理员权限模型（RBAC）** | 基于角色与权限点的访问控制，预置 super_admin / admin 等角色，后台关键操作均经 `manage:*` / `view:*` 权限门禁（应用/用户/UCenter/日志/系统/会话/审计等） |
| **安全中心** | 登录 IP 策略（黑白名单/范围）、CORS 跨域白名单（仅白名单回显来源并允许携带凭据）、维护模式开关（503）、安全响应头 |
| **多语言 SDK** | 官方提供 PHP / Java / Go / Python / JavaScript / C 六种语言的客户端 SDK，统一签名鉴权协议 |
| **API 网关** | RESTful JSON API，全局签名验签、Token 鉴权、限流与错误码规范 |
| **响应式前台** | 自适应桌面/平板/手机三端布局，含品牌 Banner、备案信息、页脚配置 |

### 1.4 技术栈

| 类别 | 技术选型 |
| --- | --- |
| 后端语言 | PHP 7.x+（原生实现，无重型框架依赖） |
| 架构模式 | 轻量 MVC（Controller / Service / Model / View 分层） |
| 数据库 | MySQL 5.7+ / 8.x（InnoDB，utf8mb4），兼容 SQLite 本地模式 |
| 前端 | 原生 HTML / CSS / JavaScript（无构建工具，三端响应式） |
| 会话存储 | 数据库表驱动（sessions 表），支持分布式部署水平扩容 |
| 加密算法 | password_hash（bcrypt）、SHA-256 摘要、令牌随机串、签名 HMAC |
| 通信协议 | HTTP(S) + JSON API、UCenter XML-RPC（uc.php 通知回调） |
| 部署要求 | Nginx / Apache + PHP-FPM，HTTPS 建议强制开启 |

### 1.5 适用场景

- 企业内部多个 Web 系统的**统一登录入口**与账号打通；
- 面向下游伙伴/客户提供**第三方授权登录**与 **SDK 快速接入**服务；
- 需要**统一安全策略**（密码规范、登录锁定、审计留痕）的多应用环境；
- 与 **Discuz! UCenter** 生态（ucenter.heicat.com 等）共建用户中心的场景。

### 1.6 快速体验

| 入口 | 地址 |
| --- | --- |
| 前台门户 | `http://<host>/index.php` |
| 后台管理 | `http://<host>/admin.php?c=login` |
| API 网关 | `http://<host>/api/index.php` |
| UCenter 回调 | `http://<host>/api/uc.php` |
| OAuth 入口 | `http://<host>/oauth.php` |
| 健康检查 | `http://<host>/health.php` |
| 在线开发文档 | `http://<host>/docs.php` |

安装方式：首次部署运行 `public/install.php` 完成初始化（生成管理员、写入 `data/install.lock`）。

> **生产上线必读**：部署步骤、生产配置核对、回滚方案、备份策略、监控告警与日志轮转，
> 统一见 **[`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md)**（含上线前检查清单）。
> 其中最容易出错的一项是 UCenter 驱动：默认 `http`，若停留在 `mock`（本地模拟）会导致
> 注册/登录不写入真实 UCenter，`/health.php` 会直接判定为 `degraded`。

## 2. 目录架构说明

### 2.1 顶层结构总览

```
统一身份认证中心 (UIC)
├── app/                    # 应用核心代码（业务逻辑）
├── config/                 # 全局配置文件
├── data/                   # 运行时数据（数据库凭据、SQL、缓存、备份、SDK包）
├── docs/                   # 项目文档（Markdown 文档集）
├── docs.php                # 在线开发者文档页面（浏览器访问入口）
├── public/                 # Web 根目录（唯一对外暴露的目录）
├── sdk/                    # 多语言客户端 SDK 源码与示例
├── tools/                  # 运维工具脚本（自检、重置密码等）
├── README.md               # 本说明文档
└── (历史报告/交付文档)      # 开发过程中产出的各类说明文档
```

### 2.2 目录逐项详解

#### `app/` —— 应用核心

```
app/
├── bootstrap.php           # 应用启动引导：加载配置、核心类、公共函数（入口必先引用）
├── Api/                    # API 网关处理器（RESTful JSON 接口）
├── Core/                   # 核心基础组件
│   ├── Authcode.php        # UCenter 通信加解密/签名算法（authcode）
│   ├── Config.php          # 配置中心（合并 config.php 与 settings 表，支持分组/默认值）
│   ├── Database.php        # 数据库连接与查询封装（PDO）
│   ├── IpPolicy.php        # 登录 IP 策略引擎（黑名单/白名单/网段匹配）
│   ├── Logger.php          # 审计日志写入组件（logs 表）
│   ├── OAuth.php           # OAuth 第三方登录框架（QQ/微信等）
│   ├── Rbac.php            # 后台权限控制（角色/权限位）
│   ├── Request.php         # HTTP 请求封装（参数清洗、输入读取）
│   ├── Response.php        # 响应封装（JSON/重定向/状态码）
│   ├── SecureHeaders.php   # 安全响应头设置（CSP/X-Frame-Options 等）
│   ├── Sms/                # 短信网关适配器目录（可插拔）
│   │   ├── TencentSms.php  #   腾讯云短信
│   │   ├── SmsBao.php      #   短信宝
│   │   ├── AliyunSms.php   #   阿里云短信
│   │   └── HuaweiSms.php   #   华为云短信
│   ├── Totp.php            # TOTP 时间型一步口令（两步验证）
│   ├── UCenter/            # UCenter 客户端驱动
│   │   ├── HttpDriver.php  #   真实 UCenter HTTP 驱动
│   │   └── MockDriver.php  #   本地模拟驱动（联调/离线）
│   ├── UCenterClient.php   # UCenter 客户端封装（统一入口）
│   ├── UserSession.php     # 用户会话管理（Cookie/会话表/记住登录）
│   ├── Validator.php       # 输入校验器
│   └── XmlKit.php          # XML 解析器（UCenter 通知报文）
├── Model/                  # 数据访问层（数据表映射）
│   ├── AdminModel.php      # 管理员表
│   ├── AppModel.php        # 应用（SP）表
│   ├── CaptchaModel.php    # 图形验证码令牌表
│   ├── ProfileModel.php    # 用户资料表
│   ├── SsoModel.php        # SSO 会话/票据/令牌表
│   └── VerifyModel.php     # 验证码（短信/邮件）表
├── Service/                # 业务服务层
│   ├── DeletionService.php # 账号注销申请服务
│   ├── OAuthService.php    # 第三方登录绑定服务
│   ├── SdkPackageService.php # SDK 包下载/版本管理服务
│   ├── SmsSender.php       # 短信发送门面（选路/故障切换）
│   ├── SsoService.php      # 单点登录核心流程服务
│   └── UserService.php     # 用户业务（注册/资料/安全/密码）
└── View/                   # 视图模板（PHP 模板）
    ├── admin/              # 后台页面模板
    ├── layout/             # 公共布局（门户/后台外壳）
    ├── partials/           # 页面片段（页头/页脚/导航/验证码组件等）
    └── portal/             # 前台页面模板（首页/登录/注册/找回/资料/安全/维护页等）
```

#### `config/` —— 全局配置

```
config/
└── config.php              # 全局配置主文件（运行模式/数据库/站点/UCenter/SSO/安全/短信/验证码）
```

> 说明：`config.php` 提供初始默认值；**MySQL 的 `settings` 表优先级更高**，后台「系统设置」保存的值会覆盖本文件对应项，详见第 5 章。

#### `data/` —— 运行时数据（不应提交到公开仓库）

```
data/
├── db_cred.php             # 数据库凭据（独立存放，不在 Web 可读目录、不参与版本管理）
├── schema.sql              # 数据库结构定义（迁移/恢复/重建用）
├── install.lock            # 安装锁（防止重复安装）
├── uic.db                  # SQLite 兼容库（本地/调试模式）
├── app.db                  # 应用数据缓存库（调试模式）
├── cacert.pem              # CA 证书链（HTTPS 出站请求校验）
├── backups/                # 代码备份目录（带日期后缀的 .bak_* 文件）
├── sdk_packages/           # SDK 发布包（zip）与索引
│   ├── index.json          #   包索引元数据
│   └── UIC_SDK_v1.1.0.zip  #   SDK 打包产物
└── (sdk_packages/ 下另含 UIC_OpenAPI 等发布包)
```

> ✅ **安全提示**：项目已内置 `.gitignore`，默认排除 `data/db_cred.php`（数据库口令）、`config/config.php`、
> `data/install.lock`、`data/*.bak*`、`*.zip` / `*.docx` 等敏感与产物文件，可直接安全入库。
> 部署时数据库凭据请写入 `data/db_cred.php`（该文件已在忽略列表内）。

#### `docs/` —— 项目文档集

```
docs/
├── API.md                 # RESTful API 接口文档
├── DATABASE.md            # 数据库设计文档
├── DEPLOYMENT.md          # 部署与运维手册（上线检查/回滚/备份/监控/日志轮转）
├── MYSQL_MIGRATION.md     # SQLite → MySQL 迁移指南
├── SETTINGS_SCHEMA.md     # 设置中心与设置项分类大纲（模块→分组→设置项）
├── SMS_PROVIDERS.md       # 短信服务商接入指南
└── UCENTER.md             # UCenter 对接指南
```

#### `public/` —— Web 根目录（部署时指向此目录）

```
public/
├── .htaccess              # Apache 重写规则
├── nginx.htaccess         # Nginx 重写规则参考
├── index.php              # 前台门户入口（c=控制器&a=动作）
├── admin.php              # 后台管理入口
├── api/
│   ├── index.php          # API 网关入口（RESTful JSON）
│   └── uc.php             # UCenter 通知回调端点
├── oauth.php              # 第三方登录（OAuth）回调端点
├── health.php             # 健康检查端点
├── install.php            # 安装程序（首次部署执行，装完自动加锁）
├── assets/                # 前端静态资源
│   ├── app.css            #   前台样式
│   ├── console.css        #   后台样式
│   └── captcha.js         #   图形验证码交互脚本
├── images/                # 站点图片资源（多尺寸 Banner：1440/1024/768/480 及 webp 格式）
└── sdk/
    ├── UicClient.php      # 演示用 PHP 客户端
    └── demo.php           # SDK 接入示例页
```

#### `sdk/` —— 多语言客户端 SDK

```
sdk/
├── README.md              # SDK 使用说明
├── composer.json          # PHP SDK Composer 配置
├── contract.json          # 接口契约定义（签名/报文规范）
├── phpunit.xml            # PHP 单元测试配置
├── src/                   # PHP SDK 源码
│   ├── Auth/              #   签名/鉴权
│   ├── Http/              #   HTTP 客户端封装
│   ├── Utils/             #   工具类
│   ├── Client.php         #   客户端门面
│   ├── AuthClient.php     #   认证客户端
│   ├── UserClient.php     #   用户客户端
│   ├── CaptchaClient.php  #   验证码客户端
│   └── Config.php         #   SDK 配置
├── php/                   # 独立 PHP 版（UicSdk.php）
├── java/                  # Java 版（UicSdk.java + lib）
├── go/                    # Go 版（uicsdk + demo）
├── python/                # Python 版（uic_sdk + setup.py + tests）
├── js/                    # JavaScript 版（index.js + dist + test）
├── c/                     # C 版（uic_sdk.c/.h + demo + Makefile）
├── examples/              # 接入示例（basic_usage / quickstart / sso_callback）
└── tests/                 # 单元测试（ConfigTest / SignerTest / TokenManagerTest / RetryPolicyTest）
```

#### `tools/` —— 运维工具

```
tools/
├── reset_admin_pwd.php    # 管理员密码重置脚本（忘记密码应急）
├── selftest.php           # 系统自检（配置/数据库/UCenter 连通性）
├── ucnotify_selftest.php  # UCenter 通知自测
└── ucserver/              # 本地模拟 UCenter Server（联调用）
    └── index.php
```

## 3. 数据库字典

### 3.1 数据库概览

| 属性 | 值 |
| --- | --- |
| 数据库类型 | MySQL（InnoDB） |
| 字符集 | utf8mb4 / utf8mb4_unicode_ci |
| 数据表总数 | **18 张** |
| 默认库名 | `demo`（本地示例）/ `uic_auth`（生产模板，见 config.php） |
| 连接方式 | PDO，凭据存放于 `data/db_cred.php`（支持每种环境独立配置） |
| 时间约定 | 全部使用 **Unix 时间戳（秒）** 存储，如 `created_at` |

**数据表清单总览**

| 序号 | 表名 | 用途 | 主键 | 关键索引 |
| --- | --- | --- | --- | --- |
| 1 | `admins` | 后台管理员 | id | username(UQ) |
| 2 | `apps` | 接入应用（SP 客户端） | id | app_id(UQ)、status |
| 3 | `sessions` | SSO 用户会话 | sid | uid、expires_at、revoked |
| 4 | `tickets` | SSO 一次性票据 | ticket | app_id、uid、expires_at |
| 5 | `tokens` | API 访问令牌 | token | uid、app_id、expires_at |
| 6 | `user_profiles` | 用户资料档案 | uid | mobile、username |
| 7 | `user_oauth` | 用户第三方绑定 | id | (provider,openid) UQ、uid、unionid |
| 8 | `user_recycle` | 回收站（软删除的用户快照） | id | uid、deleted_at |
| 9 | `account_deletion` | 账号注销申请 | id | uid、status |
| 10 | `captcha_tokens` | 图形验证码令牌 | id | token(UQ) |
| 11 | `verify_codes` | 短信/邮件验证码 | id | (target,scene) |
| 12 | `login_fail` | 登录失败计数与锁定 | account | —— |
| 13 | `settings` | 系统设置 KV 存储 | s_key | s_group |
| 14 | `logs` | 审计/操作日志 | id | type、uid、level、created_at |
| 15 | `uc_members` | UCenter 会员镜像 | uid | username(UQ)、email |
| 16 | `uc_notices` | UCenter 通知记录 | id | action、created_at |
| 17 | `uc_notify_fp` | UCenter 通知防重放指纹 | fp | —— |
| 18 | `uc_calls` | UCenter 出站调用日志 | id | method、created_at |

> 全库无外键约束，通过应用层保证关联一致性；时间字段均为 `INT` 时间戳（UNIX 秒）。

---

### 3.2 表 1：`admins` —— 后台管理员表

记录后台登录账号、角色权限与登录审计信息。

| 字段 | 类型 | 空 | 默认值 | 说明 |
| --- | --- | --- | --- | --- |
| id | int(11) | 否 | 自增 | 管理员主键 |
| username | varchar(64) | 否 | —— | 登录用户名（唯一） |
| password_hash | varchar(255) | 否 | —— | 密码哈希（bcrypt，password_hash 生成） |
| realname | varchar(64) | 否 | '' | 真实姓名/显示名 |
| role | varchar(32) | 否 | 'admin' | 角色标识（admin 等） |
| permissions | text | 是 | NULL | 权限详情（JSON/逗号分隔） |
| status | int(11) | 否 | 1 | 状态：1 启用 / 0 禁用 |
| last_login_at | int(11) | 否 | 0 | 最近登录时间（时间戳） |
| last_login_ip | varchar(64) | 否 | '' | 最近登录 IP |
| created_at | int(11) | 否 | 0 | 创建时间 |
| updated_at | int(11) | 否 | 0 | 更新时间 |
| force_pwd_change | int(11) | 否 | 0 | 历史字段（首登强制改密功能已移除，保留仅为兼容旧数据） |

**索引**：`UNIQUE(username)`、`PRIMARY(id)`

---

### 3.3 表 2：`apps` —— 接入应用（SP）表

登记所有接入 UIC 的业务应用，SSO / Token 授权均以 `app_id` 为身份标识。

| 字段 | 类型 | 空 | 默认值 | 说明 |
| --- | --- | --- | --- | --- |
| id | int(11) | 否 | 自增 | 应用主键 |
| app_id | varchar(64) | 否 | —— | 应用唯一标识（对外 AppID，唯一） |
| app_secret | varchar(64) | 否 | —— | 应用密钥（AppSecret，签名用） |
| name | varchar(191) | 否 | —— | 应用名称 |
| description | varchar(500) | 是 | '' | 应用描述 |
| home_url | varchar(255) | 是 | '' | 应用首页地址 |
| callbacks | text | 是 | NULL | 回调地址列表（JSON，SSO/Token 验签回调） |
| ip_whitelist | text | 是 | NULL | IP 白名单（JSON，可选限制来源） |
| status | int(11) | 否 | 1 | 状态：1 启用 / 0 停用 |
| sso_enabled | int(11) | 否 | 1 | 是否允许 SSO 免登：1 允许 / 0 禁止 |
| sort_order | int(11) | 否 | 0 | 排序权重（越大越靠前） |
| created_at | int(11) | 否 | 0 | 创建时间 |
| updated_at | int(11) | 否 | 0 | 更新时间 |

**索引**：`UNIQUE(app_id)`、`KEY idx_apps_status(status)`

---

### 3.4 表 3：`sessions` —— SSO 用户会话表

保存用户登录后的会话，支持"记住登录"、多端设备标识与强制下线。

| 字段 | 类型 | 空 | 默认值 | 说明 |
| --- | --- | --- | --- | --- |
| sid | varchar(64) | 否 | —— | 会话 ID（Cookie `uic_sid` 对应值，主键） |
| uid | int(11) | 否 | —— | 用户 ID |
| username | varchar(64) | 否 | '' | 用户名快照 |
| source | varchar(32) | 否 | 'center' | 会话来源：center=门户 / app=应用发起 |
| app_id | varchar(64) | 否 | '' | 关联应用 ID（应用发起的会话） |
| device_id | varchar(64) | 否 | '' | 设备 ID（浏览器指纹/自定义） |
| device_label | varchar(128) | 否 | '' | 设备显示名（如"Chrome on Windows"） |
| login_ip | varchar(64) | 否 | '' | 登录时 IP |
| login_ua | varchar(255) | 否 | '' | 登录时 User-Agent |
| last_ip | varchar(64) | 否 | '' | 最近活跃 IP |
| login_at | int(11) | 否 | 0 | 登录时间 |
| last_at | int(11) | 否 | 0 | 最近活跃时间 |
| expires_at | int(11) | 否 | 0 | 过期时间 |
| remember | int(11) | 否 | 0 | 记住登录：1 是 / 0 否 |
| revoked | int(11) | 否 | 0 | 是否已强制下线：1 是 / 0 否 |
| revoked_at | int(11) | 否 | 0 | 强制下线时间 |

**索引**：`KEY idx_sessions_uid(uid)`、`KEY idx_sessions_expires(expires_at)`、`KEY idx_sessions_revoked(revoked)`

---

### 3.5 表 4：`tickets` —— SSO 一次性票据表

单点登录跳转校验用的短期票据，一次使用即失效。

| 字段 | 类型 | 空 | 默认值 | 说明 |
| --- | --- | --- | --- | --- |
| ticket | varchar(64) | 否 | —— | 票据字符串（主键） |
| uid | int(11) | 否 | —— | 用户 ID |
| username | varchar(64) | 否 | '' | 用户名快照 |
| app_id | varchar(64) | 否 | —— | 目标应用 ID |
| callback | varchar(255) | 否 | '' | 授权回调地址 |
| client_ip | varchar(64) | 否 | '' | 签发时客户端 IP |
| created_at | int(11) | 否 | 0 | 创建时间 |
| expires_at | int(11) | 否 | 0 | 过期时间 |
| used | int(11) | 否 | 0 | 是否已使用：1 是 / 0 否 |
| used_at | int(11) | 否 | 0 | 使用时间 |
| used_ip | varchar(64) | 否 | '' | 使用（核销）时 IP |

**索引**：`KEY idx_tickets_app(app_id)`、`KEY idx_tickets_uid(uid)`、`KEY idx_tickets_exp(expires_at)`

---

### 3.6 表 5：`tokens` —— API 访问令牌表

为第三方应用签发的长效访问令牌（Bearer Token），可撤销、可限定 scope。

| 字段 | 类型 | 空 | 默认值 | 说明 |
| --- | --- | --- | --- | --- |
| token | varchar(64) | 否 | —— | 令牌字符串（主键） |
| uid | int(11) | 否 | —— | 用户 ID |
| username | varchar(64) | 否 | '' | 用户名快照 |
| app_id | varchar(64) | 否 | —— | 关联应用 ID |
| scope | varchar(64) | 否 | 'base' | 权限范围（base 基础等） |
| created_at | int(11) | 否 | 0 | 签发时间 |
| expires_at | int(11) | 否 | 0 | 过期时间 |
| revoked | int(11) | 否 | 0 | 是否已撤销：1 是 / 0 否 |
| revoked_at | int(11) | 否 | 0 | 撤销时间 |
| client_ip | varchar(64) | 否 | '' | 签发时客户端 IP |
| last_at | int(11) | 否 | 0 | 最近使用时间 |

**索引**：`KEY idx_tokens_uid(uid)`、`KEY idx_tokens_app(app_id)`、`KEY idx_tokens_exp(expires_at)`

---

### 3.7 表 6：`user_profiles` —— 用户资料档案表

用户扩展资料（昵称、头像、联系方式、个人简介）及 UCenter 同步状态。

| 字段 | 类型 | 空 | 默认值 | 说明 |
| --- | --- | --- | --- | --- |
| uid | int(11) | 否 | —— | 用户 ID（主键，关联 uc_members.uid） |
| username | varchar(64) | 否 | '' | 用户名 |
| nickname | varchar(64) | 否 | '' | 昵称 |
| avatar | varchar(255) | 否 | '' | 头像地址 |
| gender | int(11) | 否 | 0 | 性别：0 保密 / 1 男 / 2 女 |
| mobile | varchar(32) | 否 | '' | 手机号（可空） |
| birthday | varchar(16) | 否 | '' | 生日（如 1990-01-01） |
| bio | varchar(500) | 否 | '' | 个人简介 |
| sync_status | int(11) | 否 | 0 | UCenter 同步状态：0 未同步 / 1 成功 / 其他失败 |
| sync_msg | varchar(500) | 否 | '' | 同步失败信息 |
| created_at | int(11) | 否 | 0 | 创建时间 |
| updated_at | int(11) | 否 | 0 | 更新时间 |
| synced_at | int(11) | 否 | 0 | 最近同步时间 |

**索引**：`KEY idx_profiles_mobile(mobile)`、`KEY idx_profiles_username(username)`

---

### 3.8 表 7：`user_oauth` —— 用户第三方绑定表

保存用户与第三方平台（QQ/微信等）的绑定关系与令牌。

| 字段 | 类型 | 空 | 默认值 | 说明 |
| --- | --- | --- | --- | --- |
| id | int(11) | 否 | 自增 | 主键 |
| uid | int(11) | 否 | 0 | 本地用户 ID |
| provider | varchar(32) | 否 | —— | 第三方平台标识（qq/weixin 等） |
| openid | varchar(128) | 否 | —— | 平台 openid |
| unionid | varchar(128) | 否 | '' | 平台 unionid（多应用通用） |
| nickname | varchar(64) | 否 | '' | 第三方昵称 |
| avatar | varchar(255) | 否 | '' | 第三方头像 |
| access_token | varchar(512) | 否 | '' | 第三方 access_token |
| refresh_token | varchar(512) | 否 | '' | 第三方 refresh_token |
| expires_at | int(11) | 否 | 0 | access_token 过期时间 |
| raw_data | text | 是 | NULL | 第三方原始返回（JSON） |
| created_at | int(11) | 否 | 0 | 绑定时间 |
| updated_at | int(11) | 否 | 0 | 更新时间 |

**索引**：`UNIQUE uk_provider_openid(provider, openid)`、`KEY idx_uid(uid)`、`KEY idx_unionid(unionid)`

---

### 3.9 表 8：`user_recycle` —— 回收站表（软删除快照）

用户删除时在回收站保存快照以便审计/恢复，物理删除后保留此记录。

| 字段 | 类型 | 空 | 默认值 | 说明 |
| --- | --- | --- | --- | --- |
| id | int(11) | 否 | 自增 | 主键 |
| uid | int(11) | 否 | 0 | 原用户 ID |
| username | varchar(64) | 否 | '' | 原用户名 |
| email | varchar(128) | 否 | '' | 原邮箱 |
| mobile | varchar(32) | 否 | '' | 原手机号 |
| profile | text | 是 | NULL | 原资料快照（JSON） |
| reason | varchar(500) | 否 | '' | 删除原因 |
| operator | varchar(64) | 否 | '' | 操作人（管理员/用户自助） |
| deleted_at | int(11) | 否 | 0 | 删除时间 |

**索引**：`KEY idx_uid(uid)`、`KEY idx_deleted(deleted_at)`

---

### 3.10 表 9：`account_deletion` —— 账号注销申请表

用户自助申请注销账号的工单记录（需要管理员审核）。

| 字段 | 类型 | 空 | 默认值 | 说明 |
| --- | --- | --- | --- | --- |
| id | int(11) | 否 | 自增 | 主键 |
| uid | int(11) | 否 | 0 | 申请人用户 ID |
| username | varchar(64) | 否 | '' | 申请人用户名 |
| reason | varchar(500) | 否 | '' | 申请原因 |
| status | varchar(16) | 否 | 'pending' | 状态：pending 待审 / approved 通过 / rejected 拒绝 |
| remark | varchar(500) | 否 | '' | 审核备注 |
| reviewer | varchar(64) | 否 | '' | 审核人 |
| created_at | int(11) | 否 | 0 | 申请时间 |
| reviewed_at | int(11) | 否 | 0 | 审核时间 |

**索引**：`KEY idx_uid(uid)`、`KEY idx_status(status)`

---

### 3.11 表 10：`captcha_tokens` —— 图形验证码令牌表

存储图形验证码的校验答案与状态，防止 CSRF 爆破与重放。

| 字段 | 类型 | 空 | 默认值 | 说明 |
| --- | --- | --- | --- | --- |
| id | int(11) | 否 | 自增 | 主键 |
| token | varchar(64) | 否 | —— | 验证码令牌（前端携带，唯一） |
| style | varchar(16) | 否 | 'digits' | 验证码样式：digits / slide / mixed |
| answer | varchar(128) | 否 | —— | 答案（哈希存储） |
| salt | varchar(64) | 否 | —— | 加盐 |
| ip | varchar(64) | 否 | '' | 签发 IP |
| try_count | int(11) | 否 | 0 | 已尝试次数（超限作废） |
| used | int(11) | 否 | 0 | 是否已使用：1 是 / 0 否 |
| expires_at | int(11) | 否 | 0 | 过期时间 |
| created_at | int(11) | 否 | 0 | 创建时间 |

**索引**：`UNIQUE(token)`、（另有兼容索引 `idx_captcha_token(token)`）

---

### 3.12 表 11：`verify_codes` —— 短信/邮件验证码表

注册、找回密码等场景下发送的短信/邮件验证码记录（哈希存储答案）。

| 字段 | 类型 | 空 | 默认值 | 说明 |
| --- | --- | --- | --- | --- |
| id | int(11) | 否 | 自增 | 主键 |
| target | varchar(191) | 否 | —— | 接收方（手机号 / 邮箱），联合索引首列 |
| scene | varchar(32) | 否 | —— | 场景（register 注册 / findpwd 找回密码等） |
| code_hash | varchar(128) | 否 | —— | 验证码哈希 |
| salt | varchar(64) | 否 | —— | 加盐 |
| try_count | int(11) | 否 | 0 | 已尝试次数 |
| used | int(11) | 否 | 0 | 是否已使用：1 是 / 0 否 |
| ip | varchar(64) | 否 | '' | 请求 IP |
| created_at | int(11) | 否 | 0 | 创建时间 |
| expires_at | int(11) | 否 | 0 | 过期时间 |

**索引**：`KEY idx_vcode_target(target, scene)`

---

### 3.13 表 12：`login_fail` —— 登录失败计数与锁定表

按账号记录连续失败次数、锁定截止时间，实现暴力破解防护。

| 字段 | 类型 | 空 | 默认值 | 说明 |
| --- | --- | --- | --- | --- |
| account | varchar(191) | 否 | —— | 登录账号（用户名/手机号/邮箱，主键） |
| fail_count | int(11) | 否 | 0 | 连续失败次数 |
| lock_until | int(11) | 否 | 0 | 锁定截止时间戳（大于当前时间即锁定） |
| last_at | int(11) | 否 | 0 | 最近失败时间 |
| last_ip | varchar(64) | 否 | '' | 最近失败 IP |

**索引**：`PRIMARY(account)`

---

### 3.14 表 13：`settings` —— 系统设置 KV 表

后台"系统设置"的存储载体，键值对结构，**优先级高于 config.php**，支持按组管理和备注。详细配置项清单见第 5 章。

| 字段 | 类型 | 空 | 默认值 | 说明 |
| --- | --- | --- | --- | --- |
| s_key | varchar(191) | 否 | —— | 配置键（主键） |
| s_value | text | 是 | NULL | 配置值（JSON/字符串） |
| s_group | varchar(32) | 否 | 'system' | 配置分组（system/ucenter/sso/sms/mail/oauth/site/admin_pwd） |
| remark | varchar(255) | 是 | NULL | 配置说明备注 |
| updated_at | int(11) | 否 | 0 | 更新时间 |

**索引**：`KEY idx_settings_group(s_group)`

---

### 3.15 表 14：`logs` —— 审计/操作日志表

全系统审计日志，覆盖登录、注册、管理操作、安全事件等。

| 字段 | 类型 | 空 | 默认值 | 说明 |
| --- | --- | --- | --- | --- |
| id | int(11) | 否 | 自增 | 主键 |
| type | varchar(32) | 否 | —— | 日志类型（login/register/admin/security 等） |
| level | varchar(16) | 否 | 'info' | 级别：info / warn / error / danger |
| uid | int(11) | 否 | 0 | 操作用户 ID |
| username | varchar(64) | 否 | '' | 操作用户名 |
| target | varchar(191) | 否 | '' | 操作对象标识 |
| message | varchar(500) | 否 | '' | 日志内容 |
| ip | varchar(64) | 否 | '' | 来源 IP |
| ua | varchar(255) | 否 | '' | User-Agent |
| app_id | varchar(64) | 否 | '' | 关联应用 ID |
| admin_id | int(11) | 否 | 0 | 关联管理员 ID |
| extra | text | 是 | NULL | 扩展数据（JSON） |
| created_at | int(11) | 否 | 0 | 日志时间 |

**索引**：`KEY idx_logs_type(type)`、`KEY idx_logs_uid(uid)`、`KEY idx_logs_time(created_at)`、`KEY idx_logs_level(level)`

---

### 3.16 表 15：`uc_members` —— UCenter 会员镜像表

与 UCenter 同步的用户账号主数据（本系统自身用户主表）。

| 字段 | 类型 | 空 | 默认值 | 说明 |
| --- | --- | --- | --- | --- |
| uid | int(11) | 否 | 自增 | 用户 ID（主键） |
| username | varchar(64) | 否 | —— | 用户名（唯一） |
| password | varchar(64) | 否 | —— | 密码（兼容 UCenter 加密格式） |
| email | varchar(191) | 否 | —— | 邮箱 |
| regip | varchar(64) | 否 | '' | 注册 IP |
| regdate | int(11) | 否 | —— | 注册时间 |
| salt | varchar(32) | 否 | '' | 密码盐 |

**索引**：`UNIQUE(username)`、`KEY idx_uc_email(email)`

---

### 3.17 表 16：`uc_notices` —— UCenter 通知记录表

UCenter 推送过来的修改通知（改名、删号、禁用等）处理留痕。

| 字段 | 类型 | 空 | 默认值 | 说明 |
| --- | --- | --- | --- | --- |
| id | int(11) | 否 | 自增 | 主键 |
| action | varchar(64) | 否 | '' | 通知动作（updatepw/deleteuser 等） |
| appid | varchar(64) | 否 | '' | 来源应用 ID |
| payload | text | 是 | NULL | 通知载荷（JSON） |
| result | varchar(500) | 否 | '' | 处理结果摘要 |
| status | varchar(16) | 否 | 'ok' | 处理状态：ok / fail |
| message | varchar(500) | 否 | '' | 处理消息 |
| ip | varchar(64) | 否 | '' | 来源 IP |
| cost_ms | int(11) | 否 | 0 | 处理耗时（毫秒） |
| created_at | int(11) | 否 | 0 | 接收时间 |

**索引**：`KEY idx_ucnotice_action(action)`、`KEY idx_ucnotice_time(created_at)`

---

### 3.18 表 17：`uc_notify_fp` —— 通知防重放指纹表

记录 UCenter 通知的签名指纹，防止重放攻击。

| 字段 | 类型 | 空 | 默认值 | 说明 |
| --- | --- | --- | --- | --- |
| fp | varchar(64) | 否 | —— | 指纹值（主键） |
| action | varchar(32) | 否 | '' | 关联动作 |
| created_at | int(11) | 否 | 0 | 记录时间（超期自动清理） |

**索引**：`PRIMARY(fp)`

---

### 3.19 表 18：`uc_calls` —— UCenter 出站调用日志表

本系统调用 UCenter（如用户查询、积分更新）的请求/响应留痕，便于排查对接问题。

| 字段 | 类型 | 空 | 默认值 | 说明 |
| --- | --- | --- | --- | --- |
| id | int(11) | 否 | 自增 | 主键 |
| method | varchar(64) | 否 | —— | 调用的接口方法名 |
| request | text | 是 | NULL | 请求参数（JSON） |
| response | text | 是 | NULL | 响应内容（JSON/XML） |
| status | varchar(16) | 否 | '' | 调用结果状态 |
| code | varchar(32) | 否 | '' | 返回码 |
| cost_ms | int(11) | 否 | 0 | 耗时（毫秒） |
| created_at | int(11) | 否 | 0 | 调用时间 |

**索引**：`KEY idx_uccalls_method(method)`、`KEY idx_uccalls_time(created_at)`

---

### 3.20 表间关系速查

```
uc_members (用户主数据)
   ├─ 1:1 ─ user_profiles        (资料档案，主键同为 uid)
   ├─ 1:N ─ user_oauth           (第三方绑定，按 uid)
   ├─ 1:N ─ sessions             (会话，按 uid)
   ├─ 1:N ─ verify_codes         (验证码，按 target/uid)
   └─ 1:N ─ logs                 (审计日志，按 uid)

apps (接入应用)
   ├─ 1:N ─ sessions.app_id      (应用发起的会话)
   ├─ 1:N ─ tickets.app_id       (票据目标应用)
   └─ 1:N ─ tokens.app_id        (令牌关联应用)

admins → logs.admin_id           (管理员操作审计)
settings                          独立 KV 配置
captcha_tokens / login_fail        防护型独立表
uc_members ←→ uc_notices / uc_notify_fp / uc_calls (UCenter 联动)
user_recycle / account_deletion    账号生命周期管理
```

## 4. 开发者信息

### 4.1 关于我们

| 项目 | 信息 |
| --- | --- |
| **开发公司** | **沧州虎王科技** |
| **公司定位** | 专注于企业级信息化平台、统一身份认证与单点登录解决方案的研发与技术服务 |
| **项目维护** | 本开源项目由沧州虎王科技研发团队持续维护与迭代 |
| **技术积累** | PHP Web 服务、SSO 协议、UCenter 生态对接、多语言 SDK 工程化 |

### 4.2 开源与贡献

- 本项目以开源形式发布，欢迎各位开发者 Star、Fork 与提交 Issue / Pull Request。
- 接入问题、安全漏洞、功能建议可通过 GitHub Issues 与我们联系。
- 商业合作与定制开发请联系沧州虎王科技。

---

## 5. 系统配置与选项说明

本章 **逐一解释所有配置项的具体含义与作用**。配置分为两层：

1. **配置文件层**：`config/config.php` —— 提供系统默认值（安装后生效的基准）。
2. **数据库层**：`settings` 数据表 —— 后台「系统设置」存储，**优先级高于配置文件**；同一键名在 settings 表中存在时，以 settings 值为准。

> 配置优先级：`settings表 > config.php > 代码内置默认值`。

---

### 5.1 运行模式与数据库（config.php — 基础段）

| 配置项 | 默认值 | 说明 |
| --- | --- | --- |
| `debug` | `false` | 调试模式开关。开启后显示详细错误信息与堆栈，仅建议开发环境打开；**生产环境必须为 false**，防止泄露内部信息。 |
| `timezone` | `'Asia/Shanghai'` | 系统时区，影响日志时间戳、验证码/票据有效期等所有时间计算。 |
| `db.host` | `'127.0.0.1'` | 数据库主机地址。 |
| `db.port` | `3306` | 数据库端口。 |
| `db.dbname` | `'uic_auth'` | 数据库名称。 |
| `db.user` | `'uic_app'` | 数据库用户名。 |
| `db.pass` | `''` | 数据库密码。⚠️ 生产环境凭据建议写入 `data/db_cred.php`（不随代码仓库分发），config.php 中的默认值会被其覆盖。 |
| `db.charset` | `'utf8mb4'` | 数据库连接字符集，承载完整 Unicode（含 emoji）。 |
| `db.prefix` | `''` | 表前缀。如 `uic_`，用于同一库中多套系统共存时隔离；留空不加前缀。 |
| `schema_path` | `data/schema.sql` | 数据库结构定义文件路径，供迁移/恢复工具使用。 |

---

### 5.2 站点信息（config.php / settings 表均可覆盖）

| 配置项 | 默认值 | 说明 |
| --- | --- | --- |
| `site_name` | `统一身份认证中心` | 站点名称，显示在浏览器标题、页头 Logo 区域与后台。 |
| `site_slogan` | `一次登录，处处通行` | 站点标语，前台首页与登录页的品牌副标题。 |
| `site_url` | `''`（自动推断） | 站点根地址。留空时系统根据当前请求自动推断；多域名部署或需要固定地址时在此填写（如 `https://sso.example.com`）。 |
| `site_company` | `''` | 主体/公司名称，用于备案信息展示与页脚。 |
| `site_icp` | `''` | ICP 备案号，如 `京ICP备12345678号`，显示在页脚并链接至工信部备案查询。 |
| `site_icp_link` | 工信部官网 | ICP 备案查询跳转地址，一般无需修改。 |
| `site_police` | `''` | 公安联网备案号，如 `京公网安备11010802012345号`。 |
| `site_police_link` | `''` | 公安备案查询地址（各省公安厅备案查询系统）。 |
| `site_contact_email` | `''` | 对外联系邮箱，展示在页脚/联系区域。 |
| `site_contact_phone` | `''` | 对外联系电话。 |
| `site_contact_url` | `''` | 在线客服/工单系统地址。 |
| `site_footer_text` | `''` | 页脚自定义文案（纯文本，自动追加到版权行下方）。 |
| `site_footer_html` | `''` | 页脚额外 HTML（如统计代码），⚠️ 仅信任的数据可填写（会原样输出）。 |
| `site_copyright` | `''`（自动生成） | 版权声明文字，留空则按"© 年份 站点名称"自动生成。 |
| `site_logo` | `''` | 站点 Logo 图片地址，留空使用内置 U 字标。 |

---

### 5.3 SSO 会话相关（config.php / settings 表 `sso` 分组）

| 配置项 | 默认值 | 说明 |
| --- | --- | --- |
| `session_ttl` | `7200`（2 小时） | 普通登录会话的有效时长（秒）。超过后需要重新登录。 |
| `remember_ttl` | `2592000`（30 天） | "记住我"登录的会话有效时长（秒）。勾选记住登录后按此值续期。 |
| `ticket_ttl` | `60`（秒） | SSO 一次性票据（ticket）的有效期。超时未使用自动作废，防止重放。 |
| `token_ttl` | `604800`（7 天） | API 访问令牌（token）的有效期。到期后应用需重新获取。 |
| `cookie_name` | `'uic_sid'` | 会话 Cookie 名。调整后需同步更新各接入应用 SDK 的会话 Cookie 名配置。 |
| `remember_name` | `'uic_rid'` | "记住我" Cookie 名。 |

---

### 5.4 登录与安全策略（config.php / settings 表 `system` 分组）

| 配置项 | 默认值 | 说明 |
| --- | --- | --- |
| `login_fail_limit` | `5` | 登录失败次数上限。同一账号连续失败达到该次数后进入锁定。 |
| `login_lock_time` | `900`（15 分钟） | 登录锁定持续时长（秒）。锁定期间即使密码正确也无法登录。 |
| `pwd_min_length` | `6` | 密码最小长度。 |
| `pwd_max_length` | `32` | 密码最大长度。 |
| `captcha_login` | `false`（settings 中为 `1`） | 登录/找回密码等敏感操作是否显示图形验证码。开启后需先获取验证码再提交。 |
| `login_captcha` | —— | 与上等同义项（settings 表 `system` 分组），后台「系统设置-安全中心」可切换。 |
| `admin_login_captcha` | `true` | **后台独立开关**：后台登录页是否显示图形验证码，可与前台分开设置。 |
| `login_ip_policy` | `0` | 登录 IP 策略模式：`0` 不限制 / `1` 仅白名单（本人可登录）/ `2` 黑名单（禁止名单内 IP 登录）。 |
| `login_ip_list` | `''` | IP 策略名单（逗号分隔的 IP 或网段），配合 `login_ip_policy` 使用，如 `192.168.1.0/24,10.0.0.5`。 |
| `cors_origins` | `''` | API 跨域来源白名单（逗号分隔）。留空=仅允许同源调用；跨域接入 SDK 的 Web 应用需在此登记，否则浏览器的 CORS 校验会拦截。 |
| `maintenance_mode` | `0` | 维护模式：`1` 开启后前台统一返回维护公告页（503），后台不受影响。升级、迁移数据库时建议开启。 |

---

### 5.5 注册与用户（settings 表 `system` 分组 + `admin_pwd` 分组）

| 配置项 | 默认值 | 说明 |
| --- | --- | --- |
| `register_enabled` | `1` | 开放注册总开关：`1` 允许新用户注册 / `0` 关闭（注册入口提示"注册已关闭"）。 |
| `register_fields` | `{"email":1,"mobile":0}` | 注册表单可选字段（JSON）：`email`=是否需要邮箱（1 需要/0 不需要）、`mobile`=是否需要手机号。注意：字段开启后，验证码校验（短信/邮件）也随之启用。 |
| `admin_pwd_expire_days` | `0` | 管理员密码有效期（天）。`0`=永不过期；设置 N 天后到期，到期登录提示引导改密。 |
| `admin_pwd_changed_at_*` | —— | 管理员最近改密时间（按管理员 ID 后缀），供密码有效期策略计算使用。 |
| `admin_pwd_history_*` | —— | 管理员历史密码哈希列表（JSON），用于"防重用"校验，新密码不得与近期历史密码相同。 |

---

### 5.6 图形验证码（config.php / settings 表 `system` 分组）

| 配置项 | 默认值 | 说明 |
| --- | --- | --- |
| `captcha_style` | `slide` | 默认验证方式：`slide` 滑动拼图 / `click` 文字点选 / `icon` 图标选择 / `passive` 无感验证 / `digits` 纯数字 / `mixed` 数字+字母。后台「系统设置」可随时切换。 |
| `captcha_fallback` | `slide` | 无感验证（passive）评分未通过时自动回退的交互式验证方式：`slide` / `click` / `icon` / `digits` / `mixed`。 |
| `login_captcha` / `captcha_login` | `1` | 登录/找回密码显示图形验证码开关（前台）。 |
| `admin_login_captcha` | `1` | 后台登录图形验证码开关（独立于前台）。 |

相关存储表：`captcha_tokens`（校验答案、尝试次数、有效期、防重放）。

---

### 5.7 短信验证码网关（config.php / settings 表 `sms` 分组）

短信网关采用**可插拔架构**（适配器位于 `app/Core/Sms/`），新增服务商无需改动发送逻辑。

| 配置项 | 默认值 | 说明 |
| --- | --- | --- |
| `sms_enabled` | `0` | 短信发送总开关。`1` 启用后短信验证码功能生效；`0` 时短信发送进入调试模式（验证码直接返回于响应，便于联调）。 |
| `sms_provider` | `''` | 主服务商：`tencent` / `smsbao` / `aliyun` / `huawei`。留空=未接入（仅调试模式可发）。 |
| `sms_code_style` | `digits` | 验证码内容样式：`digits` 纯数字 6 位 / `letters` 纯字母 4 位 / `mixed` 数字+字母 4 位。 |
| `sms_strategy` | `failover` | 多服务商选路策略：`failover` 故障自动切换 / `priority` 按优先级轮流 / `roundrobin` 轮询。 |
| `sms_common.code_ttl` | `600` | 验证码有效时长（秒），超时未使用即失效。 |
| `sms_common.send_interval` | `60` | 同一手机号发送间隔（秒），防止频繁骚扰。 |
| `sms_common.daily_limit` | `10` | 同一手机号每日发送上限。 |
| `sms_common.ip_daily_limit` | `30` | 同一 IP 每日发送上限。 |
| `sms_common.template_var` | `code` | 短信模板中的验证码变量名（如 `{code}`）。 |
| `sms_common.strategy` | `failover` | 通用选路策略兜底值。 |
| `sms_providers` | `[]` | 各服务商私有配置（JSON）：`{ "tencent": { "enabled": true, "priority": 1, ... } }`。敏感字段可留空交由环境变量注入（如 `UIC_SMS_TENCENT_SECRET_ID`）。 |

**各服务商参数（settings 表）**：

| 配置项 | 所属服务商 | 说明 |
| --- | --- | --- |
| `sms_tencent_sdk_app_id` | 腾讯云 | 短信应用 SdkAppId。 |
| `sms_tencent_secret_id` | 腾讯云 | SecretId（AK）。 |
| `sms_tencent_secret_key` | 腾讯云 | SecretKey（SK），敏感。 |
| `sms_tencent_region` | 腾讯云 | 地域，默认 `ap-guangzhou`。 |
| `sms_tencent_sign` | 腾讯云 | 短信签名（需在腾讯云控制台审核）。 |
| `sms_tencent_template_id` | 腾讯云 | 短信模板 ID。 |
| `sms_smsbao_user` | 短信宝 | 账号。 |
| `sms_smsbao_pass` | 短信宝 | 密码，敏感。 |
| `sms_smsbao_sign` | 短信宝 | 短信签名（短信内容前缀）。 |
| `sms_aliyun_access_key_id` | 阿里云 | AccessKeyId。 |
| `sms_aliyun_access_key_secret` | 阿里云 | AccessKeySecret，敏感。 |
| `sms_aliyun_region` | 阿里云 | 地域，默认 `cn-hangzhou`。 |
| `sms_aliyun_sign_name` | 阿里云 | 短信签名。 |
| `sms_aliyun_template_code` | 阿里云 | 短信模板 CODE。 |

> 华为云 `huawei` 为预留适配器，接入参数同理会话（app_key/app_secret/signature/template）。

---

### 5.8 邮件通知（settings 表 `mail` 分组）

| 配置项 | 说明 |
| --- | --- |
| `mail_enabled` | 邮件发送总开关：`1` 启用 / `0` 停用（此时邮箱验证码退化为调试返回）。 |
| `mail_host` | SMTP 服务器地址（如 `smtp.exmail.qq.com`）。 |
| `mail_port` | SMTP 端口（默认 `465` SSL / `587` TLS）。 |
| `mail_user` | SMTP 账号（发件邮箱）。 |
| `mail_pass` | SMTP 密码/授权码，敏感。 |
| `mail_from` | 发件人显示名/地址，留空默认用 `mail_user`。 |

---

### 5.9 第三方登录 OAuth（settings 表 `oauth` 分组）

| 配置项 | 说明 |
| --- | --- |
| `oauth_qq` | QQ 互联配置（JSON）：`{"enabled":0,"appid":"","secret":""}`。`enabled`=开关、`appid`=开放平台分配的 AppID、`secret`=AppKey（敏感）。填入并启用后，前台登录页自动出现"QQ 登录"入口。 |
| （预留 `oauth_weixin` 等） | 其他平台可在 `app/Core/OAuth.php` 扩展，结构同 QQ。 |

---

### 5.10 UCenter 对接（config.php `ucenter` 段 / settings 表 `ucenter` 分组）

UCenter 是 Discuz! 系列生态的用户中心服务，本系统以客户端身份接入，实现跨系统统一用户。

| 配置项 | 默认值 | 说明 |
| --- | --- | --- |
| `ucenter.enabled` / `uc_enabled` | `1` | UCenter 功能总开关。关闭后不再进行任何 UCenter 同步/通知处理。 |
| `ucenter.driver` / `uc_driver` | `http` | 对接驱动：`http` = 走 HTTP 协议对接真实 UCenter Server；`mock` = 本地模拟（无需 UCenter 即可联调，仅开发/演示用）。 |
| `ucenter.url` / `uc_url` | `https://ucenter.heicat.com/` | UCenter Server 地址。 |
| `ucenter.ip` / `uc_ip` | `''` | UCenter Server 的 IP 地址（域名解析异常时备用，可留空）。 |
| `ucenter.key` / `uc_key` | 安装时自动生成 | **UC 通信密钥**。安装程序随机生成并写入 settings，UCenter 端必须配置完全相同的值，否则签名校验失败（authcode 解密失败）。 |
| `ucenter.appid` / `uc_appid` | `2` | 本应用在 UCenter 中的应用 ID（Ucenter 后台注册后分配）。 |
| `ucenter.connect` / `uc_connect` | `post` | 通信方式：`post` =HTTP POST 请求（推荐）；`mysql` = 直连 UCenter 数据库（不推荐）。 |
| `ucenter.charset` / `uc_charset` | `utf-8` | 与 UCenter 通信的字符集。 |
| `ucenter.timeout` / `uc_timeout` | `10` | 调用 UCenter 的超时时间（秒）。 |
| `uc_ssl_verify` | `1` | HTTPS 证书校验：`1` 校验（生产推荐）/ `0` 跳过（自签名证书联调用，⚠️ 有中间人风险）。 |
| `uc_notify_enabled` | `1` | 接收 UCenter 推送通知总开关。 |
| `uc_notify_actions` | `{}` | 各通知动作（updatepw/deleteuser 等）本地处理开关（JSON）。 |
| `uc_notify_ips` | `''` | 通知来源 IP 白名单（逗号分隔）。留空=不限制来源。 |
| `uc_notify_ttl` | `3600` | 通知时间戳允许偏差（秒），防止重放；`0`=不校验时间戳（不推荐）。 |
| `uc_notify_strict` | `0` | 严格模式：`1` 时未知通知动作直接拒绝；`0` 宽容处理。 |
| `uc_notify_plain` | `0` | 允许明文直传通知（跳过签名校验），仅调试用，**生产必须关闭**。 |
| `uc_notify_keep` | `500` | 通知记录（uc_notices 表）保留条数，超出自动清理。 |
| `uc_badwords` | `[]` | UCenter 下发的词语过滤列表（昵称/用户名黑名单词），自动同步缓存。 |
| `uc_credit_settings` | `[]` | UCenter 下发的积分设置（JSON），供本地业务对齐。 |
| `uc_hosts` | `[]` | UCenter 下发的域名解析缓存（JSON）。 |
| `uc_apps_synced_at` / `uc_client_synced_at` / `uc_badwords_at` / `uc_hosts_at` | 时间戳 | 各类 UCenter 数据最近同步时间（内部缓存刷新依据）。 |

---

### 5.11 邮件/短信默认值速查（settings 表当前实际值）

部署后的 `settings` 表常用当前值如下（可通过后台直接修改）：

| 键 | 当前值 | 分组 | 说明 |
| --- | --- | --- | --- |
| `site_name` | 统一身份认证中心 | system | 站点名称 |
| `site_slogan` | 一次登录，处处通行 | system | 站点标语 |
| `register_enabled` | 1 | system | 开放注册 |
| `register_fields` | {"email":1,"mobile":0} | system | 注册字段 |
| `login_captcha` | 1 | system | 登录验证码开关 |
| `admin_login_captcha` | 1 | system | 后台登录验证码 |
| `captcha_style` | slide | system | 默认验证方式（slide/click/icon/passive/digits/mixed） |
| `captcha_fallback` | slide | system | 无感验证回退方式 |
| `login_fail_limit` | 5 | system | 失败上限 |
| `login_lock_time` | 900 | system | 锁定秒数 |
| `pwd_min_length` | 6 | system | 密码最短长度 |
| `pwd_max_length` | 32 | system | 密码最长长度 |
| `maintenance_mode` | 0 | system | 维护模式 |
| `session_ttl` | 7200 | sso | 会话时长（秒） |
| `remember_ttl` | 2592000 | sso | 记住登录（秒） |
| `ticket_ttl` | 60 | sso | 票据时长（秒） |
| `token_ttl` | 604800 | sso | 令牌时长（秒） |
| `sms_enabled` | 0 | sms | 短信开关 |
| `sms_provider` | (空) | sms | 服务商 |
| `sms_code_style` | digits | sms | 验证码样式 |
| `mail_enabled` | 0 | mail | 邮件开关 |
| `uc_enabled` | 1 | ucenter | UCenter 启用 |
| `uc_driver` | http | ucenter | 驱动：http |
| `uc_url` | https://ucenter.heicat.com/ | ucenter | UCenter 地址 |
| `uc_appid` | 4 | ucenter | 本应用 AppID |
| `uc_notify_enabled` | 1 | ucenter | 通知接收开关 |

---

### 5.12 配置修改入口汇总

| 修改内容 | 入口 |
| --- | --- |
| 站点信息、备案、注册、密码策略、维护模式、验证码 | 后台 → 系统设置 |
| 登录安全、IP 策略、CORS | 后台 → 安全中心 |
| UCenter 对接参数 | 后台 → UCenter 配置 |
| 短信/邮件服务商 | 后台 → 系统设置（邮件/短信分组） |
| 第三方登录 | 后台 → 第三方登录 |
| 应用管理（AppID/Secret/回调/IP 白名单） | 后台 → 应用管理 |
| SDK 包管理（下载/版本） | 后台 → SDK 包管理 |

> ⚠️ 直接修改 `config/config.php` 仅影响未在 settings 表中存在的项；建议优先使用后台配置。数据库连接凭据如需独立，编辑 `data/db_cred.php`（不提交到版本库）。

---

## 6. 文档索引与更新日志

### 6.1 文档索引

| 文档 | 说明 |
| --- | --- |
| `README.md` | 项目总览、特性、目录结构、配置说明（本文件） |
| `docs/DEPLOYMENT.md` | **部署与运维手册**：上线检查清单、生产配置、回滚、备份、监控、日志轮转、故障速查 |
| `docs/API.md` | Open API 全量接口文档（用户/SSO/管理/验证码/通行密钥/企业级能力/产品展示） |
| `docs/PASSKEY.md` | 通行密钥 / WebAuthn 接入指南（约束、接口、前端集成、FAQ） |
| `docs/MEMBER_PRODUCTS.md` | 会员中心产品展示：数据模型、图标配色、后台与前台接口 |
| `docs/CAPTCHA_UPGRADE.md` | 人机验证体系升级说明（滑动拼图/点选/图标/无感/字符） |
| `docs/PASSIVE_RISK_ENGINE.md` | 无感验证风险引擎（三维评分、权重、拦截策略） |
| `docs/ADMIN_FINDPWD.md` | 管理员找回密码链路与启用条件 |
| `docs/SETTINGS_SCHEMA.md` | 系统设置三级大纲（模块→分组→设置项） |
| `docs/UCENTER.md` | UCenter 对接与双向通知 |
| `docs/SMS_PROVIDERS.md` | 可插拔短信网关接入 |
| `docs/DATABASE.md` / `docs/MYSQL_MIGRATION.md` | 数据库字典与迁移说明 |
| `CHANGELOG.md` | 版本更新日志（新增功能 / 修复 / 不兼容变更） |

### 6.2 更新日志

详见 [`CHANGELOG.md`](./CHANGELOG.md)。

---

*文档版本：v1.1 ｜ 项目：统一身份认证中心（UIC） ｜ 开发者：沧州虎王科技*