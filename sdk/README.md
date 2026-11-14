# UIC SDK 开发文档

## 目录结构

```
sdk/
├── composer.json          Composer 配置（包名 uic/sdk）
├── contract.json          接口契约（SDK 行为权威来源）
├── src/                   PHP PSR-4 源码（SDK 统一入口 + 各业务客户端）
│   ├── Client.php         SDK 统一入口（auth()/user()/captcha() 工厂）
│   ├── Config.php         配置管理
│   ├── AuthClient.php     认证/SSO 客户端
│   ├── UserClient.php     用户业务客户端
│   ├── CaptchaClient.php  验证码客户端（issue/verify）
│   ├── Http/Request.php   HTTP 请求封装
│   ├── Auth/TokenManager.php Token 管理器（内存 + 文件持久化）
│   ├── Utils/Signer.php   HMAC-SHA256 请求签名工具
│   └── Exception/         ApiException / NetworkException / AuthException
├── php/UicSdk.php         单文件零依赖版（可直接 require，无需 Composer）
├── examples/              PHP 示例工程（见下方「示例工程」）
│   ├── quickstart.php      快速开始
│   ├── basic_usage.php     完整示例
│   └── sso_callback.php    SSO 回调处理
├── python/                Python SDK（uic_sdk/ + setup.py + tests/）
├── js/                    JavaScript SDK（index.js + dist/ + test/）
├── go/                    Go SDK（uicsdk/ + go.mod + demo）
├── java/                  Java SDK（UicSdk.java + lib/）
├── c/                     C SDK（uic_sdk.c/.h + Makefile + demo.c）
└── tests/                 PHP 单元测试（Config/RetryPolicy/Signer/TokenManager）
```

> 各语言 SDK 统一遵循 `contract.json` 描述的签名鉴权协议。发布的压缩包（见「发布与打包」）内部统一为 `docs/` + `examples/` + `libs/` 结构。

## 核心类说明

### Client
SDK 统一入口，提供 `auth()`、`user()`、`captcha()` 三个子客户端的工厂方法。

### Config
配置管理，支持 base_url、app_id、app_secret、timeout、debug 等参数。

### AuthClient
- `getAuthorizeUrl($callback, $state)` - 获取 SSO 授权 URL
- `exchangeToken($ticket)` - ticket 换 token
- `getUserInfo($token)` - 获取用户信息
- `logout($token)` - 单点退出
- `testConnect()` - 通信测试

### UserClient
- `register($data, $autoLogin)` - 用户注册
- `login($account, $password, $remember)` - 用户登录
- `getInfo()` - 获取当前用户信息
- `changePassword($old, $new, $confirm)` - 修改密码
- `updateProfile($data)` - 修改资料
- `getSecurity()` - 获取安全信息
- `checkUnique($field, $value)` - 唯一性检查

### CaptchaClient
- `issue($style)` - 获取验证码
- `verify($token, $answer)` - 校验验证码

### Signer
HMAC-SHA256 请求签名工具，用于服务端 API 调用鉴权。

### TokenManager
Token 内存缓存 + 文件持久化，支持过期检测。

## 异常处理

| 异常类 | 场景 |
|--------|------|
| NetworkException | 网络请求失败、HTTP 5xx |
| ApiException | API 返回业务错误（code != 0） |
| AuthException | 认证失败、token 过期 |

## 安装

```bash
composer require uic/sdk
```

或手动复制 sdk/ 目录到项目，引入 autoload.php。

## 环境要求

- PHP >= 7.4
- ext-json
- ext-curl

## 调试

开启 debug 模式：
```php
$client = new Client([
    'debug'    => true,
    'log_file' => '/path/to/sdk.log',
]);
```

---

## 示例工程

`sdk/examples/` 提供可直接运行的 PHP 示例（需 PHP ≥ 7.4 + ext-curl/ext-json）：

| 示例 | 说明 |
| --- | --- |
| `quickstart.php` | 最小可运行：初始化 Client、SSO 授权跳转、Token 换用户信息 |
| `basic_usage.php` | 完整示例：注册/登录/改密/改资料/验证码等 `AuthClient` + `UserClient` 调用 |
| `sso_callback.php` | SSO 回调：接收 `ticket` → `exchange` 换 token → `userinfo` 取资料 |
| `captcha_flow.php` | 人机验证流程：`captcha/issue` 生成题目 + `captcha/verify` 预检 |
| `login_code.php` | 验证码登录：`user/send_login_code` + `user/login_code` 免密登录 |
| `passkey_flow.php` | 通行密钥：`passkey/status` 探测 + 注册/认证选项获取（含前端仪式片段） |
| `admin_products.php` | 后台产品 CRUD：`admin/login` 拿会话 → `admin_product/*` 增删改查 |
| `sso_full_flow.php` | 完整 SSO：授权 URL → ticket 换 token → 取资料 → 单点退出 |
| `_helper.php` | 零依赖 HTTP 调用小工具（被上述示例复用，非独立示例） |
| `legacy/` | 已废弃的旧 `public/sdk` 演示，仅供历史参考 |

各语言对应示例位于 `sdk/<lang>/`（如 `python/tests/`、`js/test/`、`go/demo`、`java/lib`、`c/demo.c`）。

运行 PHP 示例：

```bash
# 修改示例中的 base_url / app_id / app_secret 后执行
php sdk/examples/quickstart.php
```

---

## 发布与打包

源码仓库通过 `tools/build_packages.py` 统一产出可分发压缩包，所有包内部目录结构保持一致：

```
<PackageName>/
├── README.md            包说明（含版本、适用语言、快速开始）
├── VERSION              版本号
├── CHANGELOG.md         本包变更记录（指向主仓库 CHANGELOG）
├── docs/                接口文档（API.md / PASSKEY.md / MEMBER_PRODUCTS.md 等）
├── examples/            可运行示例工程（与 docs 描述一致）
└── libs/                SDK 依赖 / 编译产物（如 Java lib/、JS dist/）
```

发布包清单（位于仓库根目录，亦可在后台「SDK 包管理」上传同步）：

| 包名 | 内容 |
| --- | --- |
| `UIC_OpenAPI_v1.1.0.zip` | 开放平台完整源码（app/ + public/ + sdk/ + docs/） |
| `UIC_SDK_v1.1.0.zip` | 多语言 SDK 源码 + 示例 |
| `应用管理模块SDK源码.zip` | 应用管理相关 SDK 模块 |
| `开放平台V1.0.1.zip` | 开放平台合集（含上述子包与开发文档 docx） |

> 具体结构差异与重构说明见发布包同级的说明文档「SDK 包重构说明」。

