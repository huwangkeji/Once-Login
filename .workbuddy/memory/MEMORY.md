# 项目长期备忘（MEMORY.md）

## 人机验证体系（2026-10-13 升级后）
- 统一前端模块 `UicCaptcha`（public/assets/captcha.js），DOM 骨架在 `app/View/partials/captcha_card.php`；登录/找回密码页统一 include，不要再复制滑块脚本。
- 验证结果提示（2026-10-14）：组件内置 `.verify-toast` 浮层（成功绿/失败红+卡片晃动），完成即预检（$consume=false）反馈；失败 700ms 后自动换新题。预检失败计入 try_count（上限5）。
- 验证方式：slide / click（文字点选）/ icon（图标选择）/ passive（无感验证）/ digits / mixed；后台系统设置 `captcha_style` 切换，`captcha_fallback` 配置无感失败回退（不含 passive）。
- 后端答案约定：slide=数字坐标(容差10px)、digits/mixed=字符、click=有序下标、icon=无序下标、passive=行为 JSON；字符/点选答案存 sha256(answer+salt)。
- 关键约束：token 单次有效、5 分钟、最多 5 次尝试；`captcha_verify` 预检不消耗 token（$consume=false），登录网关终检消耗。
- click/icon 图片为服务端拼装 SVG data-uri，不依赖服务器字体（规避中文方框）；slide/digits/mixed 依赖 GD。
- 布局注意：App/Admin 对象在**旧布局**（portal/console）脚本末尾定义，视图内 init UicCaptcha 时 req 必须惰性求值（`function(){ return App.req.apply(App, arguments); }`）。**例外**：`layout/member.php`（会员中心）的 `App` 定义在 `<head>`——因为内页 passkey.js 会在 body 解析中立即调用 `App.req`，放末尾会导致通行密钥列表加载不出来（App is not defined）。
- Database 契约只有 `getInstance/getPdo/query/one/val/exec/insert/update/delete/prepare/hasTable/table/tableName/migrate/getErrors`；**没有** `all()`（多行取值用 `query()`）、**没有** `lastInsertId()`（用 `insert()` 的返回值）。新代码写 `->all(...)` / `->lastInsertId()` 会在运行时致命（会员中心曾因这两个 500）。
- 视图内不要定义全局函数（`view()` 用 require，同一进程渲染两次布局即 fatal redeclare）；产品图标等渲染逻辑放模型静态方法（`MemberProductModel::iconSvg()`）。

## 会员中心（2026-10-15 响应式改版；此前为热铁盒风格）
- **响应式重构（2026-10-15）**：`layout/member.php` 顶部通栏 = 品牌 + 分组导航（浏览与文档 ｜ 我的账号）+ 帮助和反馈 + 用户胶囊 + 主操作 `.mh-cta` + 退出图标；`<=1000px` 折叠为 `<details>` 汉堡抽屉（4 组：浏览与文档/我的账号/帮助与条款/会话）。主操作场景化：有后台会话→「进入控制台」；否则 profile→「编辑资料」、security→「修改密码」（`data-open`+`data-toggle-btn` 复用页面既有展开逻辑）。`$hasAdmin` 只读 `$_SESSION['uic_admin_id']`，不调 `AdminModel::current()`。
- profile 页 = `.mc-ph` 页头 + `.mc-hero` 身份概览 + 4 个 `.mc-group`（基础资料/联系方式/账号安全/更多信息）；保存后同步 `#heroNick/#heroAvatar/#heroEmail/#heroMobile`。security 页 = `.mc-ph` + `.mc-section`，通行密钥表包 `.t-scroll`。
- **CSS 收缩铁律**：`.mh-brand{flex:0 1 auto;min-width:0}` + `.mh-nav{flex:none}`（两者都 none → 1001~1060px 横向溢出；nav 可收缩 → 导航压扁并与右侧重叠）。断点：1000 头部折叠 / 900 单栏 / 640 紧凑 / 420 极窄 / `(max-width:900px) and (max-height:520px)` 横屏压缩。
- 回归：`node tools/member_layout_check.js`（89 断言，6 视口，截图 → `tools/_shots/`）。
- **热铁盒风格（2026-10-14 二轮）**：顶部白色通栏 `.mh-*` + 灰底两栏；侧栏导航 active 蓝色胶囊，产品为 3 列彩色磁贴 `.mc-app`（配色 `MemberProductModel::iconColor()`）；`.mc-row/.mc-section/.mc-act/.mcm` 弹窗。profile 行式条目+编辑弹窗，security 为 mc-section 区块（设备列表是 div 行，JS 选择器用 `[data-sid]` 而非 `tr[data-sid]`）。
- **路由**：`c=home` 已登录 302 → profile（旧用户中心页已下线，home.php 仅剩游客分支）。
- 两栏布局 `app/View/layout/member.php`：左栏（导航/产品磁贴/法律链接）+ 右侧 `mc-main`；profile/security 走行式/区块结构。样式全在 app.css，≤860px 侧栏折叠为顶部横排。
- 产品展示：`member_products` 表（**已在 Database::TABLES 白名单**，前缀 `uic_member_products`）；`MemberProductModel` 懒迁移会把历史无前缀表 RENAME 到当前前缀。后台路由 admin.php?c=products（菜单在 console.php 系统组「产品展示」，RBAC manage:system）；API 五端点 `m=admin_product`：list/create/update/delete/toggle（public/api/index.php）。
- 回归测试：`php tools/member_product_test.php`（39 断言，页面渲染/表前缀/API CRUD/前台展示/后台页；**非破坏性**——先快照真实产品再测试、结束原样恢复含原 id）。API 子进程驱动器 `tools/api_cli_runner.php`（CLI only；中文参数必须走临时文件，Windows argv 会破坏编码）。
- 已知次要修复：`ProfileModel::avatarUrl()` 对缺 nickname 键的 profile 不再抛 Notice；security.php 绑定成功 toast 已包 DOMContentLoaded。
- 滑块对齐（2026-10-14）：位置全由 CSS 变量驱动（`.verify-slider` 的 `--cap-p/--cap-thumb/--cap-track-h`，行程=thumb/2→轨道宽-thumb/2），JS 只写 `--cap-p`；range max=背景宽-拼图边长。浏览器脚本禁用 `global` 变量（用 `window`）。
- 滑块关键约束：`issue` 响应绝不能返回 hole_x（答案防泄露，只有 hole_y）；原生 range 的 `::-webkit-slider-thumb/::-moz-range-thumb` 必须与视觉 thumb 同尺寸（否则指针→取值映射错位）；滑块背景图用独立类名 `verify-slide-bg`（absolute 100%/100% 铺满），绝不能复用 `.verify-image`（曾被 `.auth-cap .verify-image{height:46px}` 压扁——该规则已收窄到 `.verify-mode--text`）。E2E：tools/slider_e2e_test.js（登录失败会 reset 换新题，测试须等 token+bg.complete；断言必须含渲染级缺口对齐）。
- 无感验证被动评分（2026-10-14 梳理）：`CaptchaModel::passiveScore()` 判失败三阈值硬编码为类常量 `PASSIVE_MIN_MS=600`(耗时下限)、`PASSIVE_MIN_MOVE=5`(移动采样，触屏放宽到3)、`PASSIVE_MIN_ENT=25`(方向熵=不同方向桶数/8*100)；JSON解析失败或任一不达标即失败。`verify()` passive 分支失败返回哨兵串 `__passive_fail__`（区别于普通"验证码不正确"），前端 `submitPassive()` 据此 `load(S.fallback)` 自动回退；登录网关(api/index.php 114/363)把该串翻译为"安全验证未通过，请刷新后重新验证"(406)。**设计局限**：答案为前端自报 JSON，阈值常量非配置项，算法不支持后台自定义（要可配需改 SettingsSchema+Config::get+passiveScore+captcha.js 采集参数）。
- 无感风险引擎 RiskEngine（2026-10-14 升级，已在后台「安全与验证→无感验证」全可配）：新增 `app/Model/RiskEngine.php`——前端 `captcha.js` 上报**原始轨迹+环境指纹**，`extractFromTrajectory()` 服务端重算特征（时长/点数/抖动/方向熵/方向变化/速度变异系数），三维信号（行为/环境/服务端）按可配置权重 `passive_w_behavior/_env/_server` 加权得 0-100 风险分；`decide()` 按 `passive_score_pass/challenge/block` 或强度档位 `passive_verify_intensity`(low/normal/strict/custom) 分级：`pass`(无感放行)/`challenge`(回退 `captcha_fallback`)/`block`(拦截，哨兵 `__passive_block__`)。放行策略：IP/CIDR 白名单 `passive_allowlist` + HMAC 签名信任 Cookie `uic_passive_trust`(绑定 IP，跨页无感放行，api/index.php 的 requireCaptchaIfEnabled/admin_login 校验)。反伪造：聚合特征服务端重算、信任凭证签名、validateFeatures 拒绝越界/篡改；静止用户降级 challenge 不误杀。`passive_enabled=0` 时回退旧版 `passiveScore()`（已兼容新轨迹格式）。测试 `tools/passive_risk_test.php`(27 断言)。文档 `docs/PASSIVE_RISK_ENGINE.md`。

## 设置中心（2026-10-14 重构）
- 设置项三级大纲唯一来源：`app/Core/SettingsSchema.php`（模块→分组→设置项，97 项/9 模块）。
  模块 id = settings.s_group：site/account/security/sso/mail/sms/ucenter/oauth/system。
- 后台页面 `app/View/admin/settings.php`（模块导航+搜索+未分类兜底）；保存走 `SettingsSchema::normalize()`。
- 新增设置项四步：大纲加定义 → config.php 补默认 → schema.sql 补种子 → `php tools/migrate_settings.php --apply`。
- 详见 docs/SETTINGS_SCHEMA.md。

## 登录页动态星空（2026-10-14）
- `public/assets/auth-bg.js`（canvas 星空+流星+视差）+ app.css `.auth-bg__*`（云雾/缓摆/山脊微光）。
- 整层同步闪烁已废除；逐星相位/速度独立。reduced-motion 与页签隐藏自动静止/暂停。
- 诊断钩子 `window.__authBg`（只读）。回归测试：tools/slider_geometry_test.js、tools/authbg_test.js。

## 数据库表前缀约定（重要，2026-10-14 踩坑）
- `Database::TABLES` 是**表前缀注入白名单**：`applyPrefix()` 只对白名单内的表名注入 `db.prefix`（当前 `uic_`），
  而 `hasTable()`/`tableName()` 却**总是**拼前缀。二者不一致会致命：
  新增业务表若漏加白名单 → `hasTable()` 按 `uic_xxx` 查（找不到），裸名 `CREATE TABLE xxx` 又建成真裸表
  → 每次 `ensureSchema()` 都重跑 CREATE → 第二次起报 `SQLSTATE[42S01] 1050 already exists`。
- **铁律**：任何新增业务表都要同时 ①加入 `Database::TABLES`；②建表语句用 `CREATE TABLE IF NOT EXISTS`；③`data/schema.sql` 同步。
- 已补入白名单：`user_passkeys`、`webauthn_challenges`（login.php 的「通行密钥登录」曾因此 500）。
- 通行密钥/WebAuthn：`PasskeyService`→`PasskeyModel`（表 `uic_user_passkeys`/`uic_webauthn_challenges`）；
  **域约束（2026-10-15）**：RP ID 必须是合法域名，**IP 字面量一律非法**（127.0.0.1/局域网 IP/[::1] 都会被浏览器抛
  `SecurityError: This is an invalid domain.`），且须安全上下文（https 或 **http://localhost**）。
  `PasskeyService::domainIssue()/domainUsable()` 统一判定（ip/insecure/host/ip_rpid/mismatch/rp_empty）：
  `registerOptions`/`authOptions` 前置拦截并返回中文原因；`passkey_status` 返回 `domain_ok/domain_issue/domain_reason/domain_hint`；
  security 页渲染警告 + 禁用添加按钮（已有密钥仍可 list/rename/delete）；login 页 `$passkeyUsable` 同时控制
  `autocomplete="username webauthn"` 与入口渲染；`passkey.js` 的 `isSupported()` 纳入域判定、`domainMessage()` 给中文说明、
  `translateError()` 翻译浏览器英文原报错（引用处带 `?v=20261015-pk`）。
  本机 phpStudy：`localhost` 被占位站占用（403），故须 `php -S 127.0.0.1:8099 -t public` 后走 http://localhost:8099。
  测试：`tools/passkey_domain_check.js`（23/23，IP 降级 + localhost 走通）、`tools/passkey_e2e_test.js`（8/8，CDP 虚拟认证器注册→登录）。
- API 全局异常处理（`public/api/index.php` catch）：DB 异常只向用户回「服务暂时不可用，请稍后重试」，
  细节（SQLSTATE/表名/文件行号）仅写 `logs` 表；非 DB 异常原样返回。

## 管理员找回密码（2026-10-15 新增）
- 入口：`app/View/admin/login.php` 的 `#pwdField` 内 `.login-forgot` → `admin.php?c=findpwd`
  （`public/admin.php` 已把 `findpwd` 加入未登录白名单，用 `layout/auth`）。
- 链路：`app/Service/AdminResetService.php`（scene=`admin_findpwd`）↔ 接口 `admin/findpwd_send`、`admin/findpwd_reset`；
  送达地址是 `admins.email`（`AdminModel::ensureEmailColumn()` 幂等补列，新装库由 schema.sql 带出）。
- 启用三条件：`admin_findpwd_enabled=1` + 邮件服务已开（或 debug 与 `admin_findpwd_debug` 双开的联调通道）+ 该管理员已填邮箱；
  不满足则页面降级为「联系超级管理员」+ `site_contact_email`，**不放假表单**。
- **验证码默认绝不回显**：仅 `debug` 与 `admin_findpwd_debug` **同时**开启才回显（生产严禁）。
- 安全：账号+邮箱双重匹配（不匹配措辞统一防枚举）、频控（邮箱 60s/1h×5、IP 1h×20）、
  口令 ≥8 位含字母数字、历史 5 次防重用、验证码用后即焚；重置成功递增 `admin_sess_ver_<id>`
  强制其它设备后台下线（`AdminModel::current()/authorize()` 比对 `uic_admin_ver`，旧会话无该键不误杀）。
- 测试：`php tools/admin_findpwd_test.php`（27 断言）、`node tools/admin_findpwd_e2e_test.js`（22 断言）。
  坑：页面端 `Admin.req` 是 POST+FormData，m/a 在**请求体**里，Playwright 须按 `request().postData()` 匹配。
