# SDK / 开放平台压缩包重构说明

> 配套任务：基于近期新增功能（人机验证升级、通行密钥、会员中心产品展示、管理员企业级能力、多语言 SDK）完善文档、示例，并统一重构所有分发 zip 包。
> 构建脚本：`tools/build_packages.py`（可复现，执行即原地生成下列 5 个 zip）。

---

## 一、改动文件清单

### 文档（新增 / 更新）
| 文件 | 动作 | 说明 |
| --- | --- | --- |
| `docs/API.md` | 更新 | 补充 `passkey_*`、`admin_product_*`、`captcha_issue/verify`、`user_send_login_code/login_code`、`user_oauth_unbind`、`user_deletion_request`、`admin_session_*`、`admin_totp_*`、`admin_audit_stats`、`admin_uc_notify_*`、`admin_deletion_*`、`admin_oauth_save*`、`sdkpkg_*`、`admin_site_save`、`admin_user_delete` 等全部未文档化模块；新增调用示例 |
| `docs/PASSKEY.md` | 新增（前期） | 通行密钥 / WebAuthn 接入指南 |
| `docs/MEMBER_PRODUCTS.md` | 新增（前期） | 会员中心产品展示数据模型与接口 |
| `CHANGELOG.md` | 新增 | 版本更新日志（v1.1.0 企业级能力增强） |
| `README.md` | 更新 | 特性表补充通行密钥 / 无感验证 / 会员产品 / RBAC / 多语言；新增「六、文档索引与更新日志」 |
| `sdk/README.md` | 更新 | 目录结构同步真实多语言布局；补充示例工程与发布打包说明 |

### 示例代码（新增 / 更新）
| 文件 | 动作 | 说明 |
| --- | --- | --- |
| `sdk/examples/_helper.php` | 新增 | 零依赖 HTTP 调用小工具（被示例复用） |
| `sdk/examples/captcha_flow.php` | 新增 | 图形验证码 `issue`/`verify` 流程 |
| `sdk/examples/login_code.php` | 新增 | 验证码登录 `send_login_code`/`login_code` |
| `sdk/examples/passkey_flow.php` | 新增 | 通行密钥 `status` 探测 + 注册/认证选项（含前端仪式片段） |
| `sdk/examples/admin_products.php` | 新增 | 后台产品 CRUD（登录态 + Cookie） |
| `sdk/examples/sso_full_flow.php` | 新增 | 完整 SSO：授权 → ticket 换 token → 取资料 → 单点退出 |
| `sdk/examples/quickstart.php` `basic_usage.php` `sso_callback.php` | 更新 | 改为优先 Composer 自动加载，否则用内置零依赖 `sdk/autoload.php` |
| `sdk/autoload.php` | 新增 | 零依赖 PSR-4 加载器（`UIC\SDK` → `src/`），使示例免 `composer install` 即可运行 |
| `sdk/examples/legacy/UicClient.php` `demo.php` | 迁移 | 原 `public/sdk/` 废弃 demo 移出 Web 根目录并标注 DEPRECATED |

### 打包与运行时
| 文件 | 动作 | 说明 |
| --- | --- | --- |
| `tools/build_packages.py` | 新增 | 统一构建脚本（剔除 data/vendor/node_modules/嵌套 zip 等冗余） |
| `UIC_OpenAPI_v1.1.0.zip` `UIC_SDK_v1.0.0.zip` `UIC_SDK_v1.1.0.zip` `应用管理模块SDK源码.zip` `开放平台V1.0.1.zip` | 重建 | 统一内部目录结构 |
| `data/sdk_packages/*.zip` + `index.json` | 更新 | 同步刷新后台「SDK 包管理」下载中心的内置包与元数据 |

---

## 二、重构前结构（Before）

```
UIC_OpenAPI_v1.1.0.zip        顶层散落，无包裹目录
├── README.md
├── app/  (2 项)
├── docs.php
├── public/  (1 项)
└── sdk/  (48 项)

UIC_SDK_v1.0.0.zip            命名 bug：v1.0.0 包内误裹 v1.1.0 目录
└── UIC_SDK_v1.1.0/
    ├── composer.json / contract.json / phpunit.xml / README.md
    ├── c/  go/  java/  js/  php/  python/
    ├── examples/ (仅 3 个历史示例)
    ├── src/  tests/
    └── （无 docs/、无 VERSION、无 CHANGELOG）

UIC_SDK_v1.1.0.zip
└── UIC_SDK_v1.1.0/  （同上，无 docs/VERSION/CHANGELOG）

应用管理模块SDK源码.zip      中文包名，仅 14 项（旧版 SDK 子集，缺 python/js/src/docs）
└── 应用管理模块SDK源码/
    ├── README.md  UicSdk.php  c/  go/  java/lib/gson  examples/(3)
    └── （无 docs/VERSION/CHANGELOG）

开放平台V1.0.1.zip          5.7MB，含嵌套 zip 与 data/
├── README.md
├── UIC_OpenAPI_v1.1.0.zip            ← 嵌套 zip
├── UIC_SDK_v1.0.0.zip                ← 嵌套 zip
├── UIC_SDK_v1.1.0.zip                ← 嵌套 zip
├── 应用管理模块SDK源码.zip            ← 嵌套 zip
├── UIC_SDK_开发文档.docx
├── app/ (86)  config/ (2)  data/ (17，含 sdk_packages 与备份)
├── docs/ (仅 6 篇，已过时)  docs.php  public/ (27)
├── sdk/ (2013 项，含 vendor/ 9.6MB)  tools/ (6)
└── 应用管理模块接入方案.docx
```

**主要问题**：① 包裹目录命名不统一（散落 / 中文 / 误用版本号）；② 各包缺统一 `docs/`、`examples/`、`VERSION`、`CHANGELOG`；③ 开放平台合集内嵌 4 个嵌套 zip，且夹带 `data/`（含备份与运行态）与 9.6MB `sdk/vendor/`；④ 示例陈旧、与最新文档脱节，且需 `composer install` 才能跑。

---

## 三、重构后结构（After）

所有包统一为：**单一包裹目录（与包名一致） + `README.md` + `VERSION` + `CHANGELOG.md` + `docs/`（文档集） + `examples/`（可运行示例） + 实际内容**。

```
UIC_OpenAPI_v1.1.0.zip
└── UIC_OpenAPI_v1.1.0/
    ├── README.md  VERSION  CHANGELOG.md
    ├── docs/            ← 11 篇最新文档（API/PASSKEY/MEMBER_PRODUCTS/…）
    ├── examples/        ← 8 个零依赖可运行示例
    ├── app/  config/  public/  sdk/  docs.php
    └── （已剔除 data/、sdk/vendor/）

UIC_SDK_v1.1.0.zip  /  UIC_SDK_v1.0.0.zip
└── UIC_SDK_v1.1.0/  (或 UIC_SDK_v1.0.0/，修复命名 bug)
    ├── README.md  VERSION  CHANGELOG.md
    ├── docs/  examples/  ← 一致的文档与示例
    ├── src/  php/  python/  js/  go/  java/  c/
    ├── composer.json  contract.json  phpunit.xml  tests/
    └── （已剔除 vendor/）

应用管理模块SDK源码.zip
└── 应用管理模块SDK源码/
    ├── README.md  VERSION  CHANGELOG.md
    ├── docs/  examples/    ← 与整体一致
    ├── src/  php/  python/  js/  go/  java/  c/  tests/
    └── （补全为完整多语言 SDK，与 UIC_SDK 同源）

开放平台V1.0.1.zip
├── 开放平台V1.0.1/
│   ├── README.md  VERSION  CHANGELOG.md
│   ├── docs/                ← 最新文档集
│   ├── office_docs/         ← UIC_SDK_开发文档.docx / 应用管理模块接入方案.docx（不再内嵌 zip）
│   └── （不再含 data/、嵌套 zip、vendor/）
├── UIC_OpenAPI_v1.1.0/     ← 直接展开的子包目录（自包含：含自身 docs/examples/VERSION/CHANGELOG）
└── UIC_SDK_v1.1.0/         ← 直接展开的子包目录（自包含）
```

**关键改进**：① 单包裹目录 + 统一命名；② 各包均带 `docs/`、`examples/`、`VERSION`、`CHANGELOG`、`README`；③ 开放平台合集**不再嵌套 zip**，改为展开目录，并移除 `data/`、`sdk/vendor/`、调试日志等冗余；④ 示例全部零依赖（`_helper.php` + `sdk/autoload.php`），`php -l` 通过且对真实服务可运行；⑤ 后台下载中心 `data/sdk_packages/` 与 `index.json` 同步刷新。

---

## 四、验证

- 5 个 zip 均生成成功，顶层仅含预期包裹目录；无嵌套 `.zip`、无 `data/`、`vendor/`、`node_modules/`。
- 全部示例 `php -l` 通过；`captcha_flow.php` 对本地服务实跑：成功 `issue` 取得 token、`verify` 返回 406（符合预期失败分支），无 PHP 警告。
- 构建可复现：执行 `python3 tools/build_packages.py` 即可从仓库源码重新产出全部包。
