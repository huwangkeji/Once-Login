# Open API 接口文档

统一身份认证中心对外提供统一 Open API，所有接口经 `public/api/index.php` 路由，格式：

```
POST/GET  /api/index.php?m={模块}&a={动作}
Content-Type: application/x-www-form-urlencoded 或 application/json
```

- 成功：`{"code":0,"message":"ok","data":{...}}`
- 失败：`{"code":非0,"message":"错误说明"}`
- 跨域：默认 `Access-Control-Allow-Origin: *`（生产请按需收紧）。

---

## 一、用户接口（`m=user`）

| 动作 | 参数 | 说明 |
| --- | --- | --- |
| `register` | username, password, confirm, email, mobile, nickname, gender, auto_login=1 | 注册；校验用户名/邮箱唯一性（经 UCenter），可选自动登录 |
| `login` | account(用户名/邮箱/手机), password, remember, app_id | 登录（三种账号类型均可），含登录失败限流 |
| `logout` | sid(可选) | 退出当前/指定设备 |
| `logoutall` | — | 退出全部设备 |
| `info` | （需登录） | 当前用户信息（含资料、UCenter 状态） |
| `security` | （需登录） | 账号安全：登录设备列表 + 历史 |
| `device_kill` | sid | 踢掉指定设备（需登录且属本人） |
| `password` | old_password, new_password, confirm | 修改密码（需登录） |
| `profile` | nickname/gender/birthday/bio/avatar/email/mobile | 修改扩展资料（需登录） |
| `findpwd_send` | target(邮箱/手机) | 发送找回验证码（调试模式直接回显） |
| `findpwd_reset` | target, code, new_password, confirm | 校验验证码并重置密码 |
| `check` | field(username/email/mobile), value | 唯一性检查 |
| `send_login_code` | target(邮箱/手机) | 发送「验证码登录」用的验证码（受 IP 访问策略 + 图形验证码保护） |
| `login_code` | target, code, remember, app_id | 验证码登录（无需密码，受 IP 访问策略保护） |
| `oauth_unbind` | provider(平台标识，如 wechat/qq/github) | 解除已绑定的第三方账号（需登录） |
| `deletion_request` | reason(可选) | 提交账号注销申请（需登录） |

---

## 二、SSO 单点登录接口（`m=sso`）

| 动作 | 参数 | 说明 |
| --- | --- | --- |
| `authorize` | app_id, redirect | 浏览器跳转授权：已登录→302 回 `redirect?ticket=xxx`；未登录→401 带 `login_url` |
| `exchange` | ticket, app_id, app_secret | 服务端凭票据换长期 Token（一次性） |
| `userinfo` | token(或 Authorization: Bearer), app_id | 用 Token 换取用户基础信息 |
| `logout` | token, app_id | 单点退出（吊销该 Token 及关联会话） |
| `test` | app_id, app_secret | 应用通信测试 |

**SSO 双凭证流程**
1. 应用携带 `app_id` + `redirect` 跳转到 `sso/authorize`；
2. 用户在本中心登录后，获发一次性 `ticket`（限时 60s，绑定 app）；
3. 浏览器 302 回 `redirect?ticket=xxx`；
4. 应用服务端用 `ticket + app_secret` 调 `sso/exchange` 换取长期 `token`；
5. 应用用 `token` 调 `sso/userinfo` 拿到用户资料；
6. 退出时调 `sso/logout` 实现单点退出。

---

## 三、管理接口（`m=admin`，需后台登录态）

通过 `admin.php` 登录后由浏览器 Session 鉴权；也可在管理后台直接调用。

### 控制台 / 用户
| 动作 | 参数 | 说明 |
| --- | --- | --- |
| `login` | username, password | 管理员登录（建立后台会话） |
| `logout` | — | 退出后台 |
| `findpwd_send` | username, email[, captcha_token, captcha_answer] | 管理员找回密码第一步：向该管理员预留邮箱发验证码（公开接口，受图形验证码 + IP 策略保护） |
| `findpwd_reset` | username, email, code, new_password, confirm | 管理员找回密码第二步：校验验证码并重置密码（成功后其它设备后台登录失效） |
| `stats` | — | 控制台统计（用户/会话/Token/UCenter 健康） |
| `user_list` | kw, page, size | 用户检索（基于本地扩展资料 + UCenter 摘要） |
| `user_action` | uid, act(disable/enable/kick) | 禁用/启用/踢下线 |

### 应用接入
| 动作 | 参数 | 说明 |
| --- | --- | --- |
| `app_list` | — | 应用列表 |
| `app_save` | id(编辑), name, description, home_url, callbacks, status, sso_enabled, app_id | 新增/编辑应用 |
| `app_reset_secret` | id | 重置 AppSecret |
| `app_toggle` | id, status | 启用/禁用 |
| `app_delete` | id | 删除 |
| `app_test` | id | 应用通信测试 |

### UCenter
| 动作 | 参数 | 说明 |
| --- | --- | --- |
| `uc_config` | — | 读取 UCenter 配置与连接状态 |
| `uc_save` | uc_driver, uc_enabled, uc_url, uc_ip, uc_key(留空不改), uc_appid, uc_timeout | 保存 UCenter 配置 |
| `uc_test` | — | 连接测试（ping） |
| `uc_call` | method, username, email, account, password, new_password, old_password | 单接口联调测试 |
| `uc_calls` | — | 通信记录列表 |

### 日志
| 动作 | 参数 | 说明 |
| --- | --- | --- |
| `log_list` | type, level, uid, app_id, kw, page, size | 日志检索 |
| `log_clear` | days(默认90) | 清理 N 天前日志 |

### 系统设置 / 管理员
| 动作 | 参数 | 说明 |
| --- | --- | --- |
| `settings` | group(可选) | 读取配置 |
| `settings_save` | data(JSON 对象：键=>值) | 批量保存系统设置 |
| `admin_list` | — | 管理员列表 |
| `admin_save` | id(编辑), username, password, realname, role | 新增/编辑管理员 |
| `admin_password` | old_password, new_password | 修改当前管理员密码 |
| `admin_toggle` | id, status | 启用/禁用 |
| `admin_delete` | id | 永久删除用户（含 UCenter 注销 + 本地数据清理，RBAC manage:users） |

---

## 四、图形验证码接口（`m=captcha`，无需登录）

用于登录 / 找回密码等场景的人机验证。`CaptchaModel` 支持多种验证方式，由后台 `captcha_style` 切换：

- `slide` 滑块拼图（返回拼图位置，答案容差 10px）
- `click` 文字点选（服务端拼装 SVG，返回有序下标，答案存 sha256）
- `icon` 图标选择（返回无序下标）
- `digits` / `mixed` 字符输入（答案存 sha256）
- `passive` 无感验证（前端上报行为轨迹，服务端风险引擎评分）

| 动作 | 参数 | 说明 |
| --- | --- | --- |
| `issue` | style(可选：`slide/click/icon/passive/digits/mixed`，缺省取后台 `captcha_style`) | 生成一道验证题，返回 `token` + 渲染数据（拼图坐标 / 点选 SVG / 字符图片等） |
| `verify` | token, answer | 预检验证答案（`$consume=false`，**不消耗 token**），成功返回 `验证通过`；失败返回原因。无感验证未通过时返回特殊提示，前端据此刷新为交互式验证码 |

> **安全约束**：token 单次有效、5 分钟内、最多 5 次尝试；前端即时预检不消耗 token，登录等最终校验由请求中间件再次调用并消耗。无感验证失败会触发回退到 `captcha_fallback` 配置的可见验证方式。详见 `docs/CAPTCHA_UPGRADE.md`、`docs/PASSIVE_RISK_ENGINE.md`。

---

## 五、通行密钥 / WebAuthn 接口（`m=passkey`）

完整接入文档见 **`docs/PASSKEY.md`**。核心约束：RP ID 必须是合法域名，**IP 访问 / 非 https / 域不匹配时 `status.domain_ok=false`**，前端应优雅降级（禁用添加按钮 + 中文提示），而非抛出浏览器原生 `SecurityError`。

| 动作 | 参数 | 说明 | 鉴权 |
| --- | --- | --- | --- |
| `status` | — | 公开探测：`enabled, rp_id, domain_ok, domain_issue, domain_reason, domain_hint` | 公开 |
| `register_options` | — | 生成注册选项（challenge 等），供安全中心添加新密钥 | 登录态 |
| `register_finish` | tx, client_data, attestation, label | 完成注册，写入新密钥 | 登录态 |
| `auth_options` | — | 生成认证选项（已登录用户可免交互） | 公开 |
| `auth_finish` | tx, credential_id, client_data, authenticator_data, signature, remember, app_id | 完成认证并创建会话 | 公开 |
| `list` | — | 列出当前用户全部密钥（含 `created_text/last_used_text/backup_eligible` 等） | 登录态 |
| `rename` | id, label | 重命名密钥 | 登录态 |
| `delete` | id | 删除密钥 | 登录态 |

---

## 六、管理员企业级能力（`m=admin`，续）

### 会话管理（RBAC：`view:sessions` / `manage:sessions`）
| 动作 | 参数 | 说明 |
| --- | --- | --- |
| `session_list` | kw, page, size(10~50) | 在线会话总览（分页 + 关键字，覆盖用户名/昵称/IP/UA/设备，含用户名回退链与当前会话标记） |
| `session_kill` | sid | 强制下线指定会话（不可踢除当前管理员自身会话） |
| `session_kick_user` | uid | 强制下线该用户全部会话，并吊销其 SSO Ticket |

### 管理员 TOTP 二步验证（本人操作，无需额外权限点）
| 动作 | 参数 | 说明 |
| --- | --- | --- |
| `totp_status` | — | 查询本管理员是否已启用 TOTP（返回 `enabled`/`has_secret`） |
| `totp_setup` | — | 生成/重置 TOTP Secret + `otpauth://` URI（重置会先关闭启用态） |
| `totp_enable` | code | 校验动态码并启用（下次登录需输入动态验证码） |
| `totp_disable` | code | 校验动态码并关闭 |

### 安全审计中心（RBAC：`view:audit`）
| 动作 | 参数 | 说明 |
| --- | --- | --- |
| `audit_stats` | — | 风险概览（今日失败/锁定中/UCenter 失败/管理员操作/日志总量/错误级/今日登录）+ 今日告警类型分布 + 近 7 天登录失败趋势 + 最新告警明细 |

### UCenter 通知接收（RBAC：`manage:ucenter`）
| 动作 | 参数 | 说明 |
| --- | --- | --- |
| `uc_notify` | — | 读取通知接收配置（`enabled/ttl/strict/ips/keep/plain`）、动作开关、最近 40 条记录与统计 |
| `uc_notify_save` | `uc_notify_enabled/ttl/strict/keep/plain/ips`, `uc_notify_actions`(JSON) | 保存通知接收配置与动作本地处理开关 |
| `uc_notify_test` | action | 用与 UCenter 完全一致的方式（`authcode` 加密 `code`）向本站 `api/uc.php` 发真实通知，验证「网络可达 + 密钥一致 + 解密正确 + 回执成功」（HTTP 回环 + 进程内直调双通道） |
| `uc_notify_clear` | — | 清空通知记录表 |

### 第三方登录（RBAC：`manage:system`）
| 动作 | 参数 | 说明 |
| --- | --- | --- |
| `oauth_save` | provider, enabled, appid, secret, private_key | 保存单个平台配置（如微信/QQ/GitHub，共 11 个平台） |
| `oauth_save_global` | auto_register | 保存全局策略（是否允许第三方登录自动注册账号） |

### 账号注销审核（RBAC：`view:users`）
| 动作 | 参数 | 说明 |
| --- | --- | --- |
| `deletion_list` | status(pending/...), page | 注销申请列表 |
| `deletion_review` | id, approve(1/0), remark | 通过 / 驳回审核（含审核人记录） |

### 站点信息与用户管理
| 动作 | 参数 | 说明 |
| --- | --- | --- |
| `site_save` | `site_name/slogan/url/logo/company/icp/icp_link/police/police_link/contact_email/contact_phone/contact_url/copyright/footer_text/footer_html` | 保存站点信息（RBAC `manage:system`） |
| `user_delete` | uid | 永久删除用户：同步注销 UCenter 核心身份 + 清理本地会话/Token/票据/资料（RBAC `manage:users`） |

### SDK 压缩包管理（RBAC：`manage:system`）
| 动作 | 参数 | 说明 |
| --- | --- | --- |
| `sdkpkg_list` | — | 列出已发布的 SDK 压缩包 |
| `sdkpkg_upload` | file(multipart/form-data), title, version, desc | 上传 SDK 包，前端下载中心自动同步 |
| `sdkpkg_delete` | name | 删除 SDK 包（内置包不可删） |

---

## 七、会员中心产品展示（`m=admin_product`，RBAC：`manage:system`）

完整数据模型 / 图标配色 / 前台展示见 **`docs/MEMBER_PRODUCTS.md`**。

| 动作 | 参数 | 说明 |
| --- | --- | --- |
| `list` | — | 全部产品（含禁用项） |
| `create` | name, url, description, icon, sort_order, enabled | 新增产品（`icon` 取 12 种内置键之一，如 `link/doc/chat/store/tool/cloud/mail/shield/star/heart/globe/code`） |
| `update` | id, name, url, description, icon, sort_order, enabled | 编辑产品 |
| `delete` | id | 删除产品 |
| `toggle` | id, enabled | 启用 / 禁用 |

> 前台会员中心通过 `MemberProductModel` 读取 `uic_member_products` 表，按 `sort_order` 升序、`enabled=1` 展示彩色磁贴。

---

## 调用示例（curl）

```bash
# 注册
curl -X POST http://your-host/api/index.php \
  -d "m=user&a=register&username=alice&password=secret123&email=alice@demo.com&auto_login=1"

# SSO 票据换 Token（服务端）
curl -X POST http://your-host/api/index.php \
  -d "m=sso&a=exchange&ticket=TICKET&app_id=APPID&app_secret=APPSECRET"

# 用 Token 取用户资料
curl http://your-host/api/index.php?m=sso&a=userinfo&token=TOKEN&app_id=APPID

# 生成图形验证码（无需登录）
curl http://your-host/api/index.php?m=captcha&a=issue&style=slide

# 验证码登录（先 send_login_code 拿到 code）
curl -X POST http://your-host/api/index.php \
  -d "m=user&a=login_code&target=alice@demo.com&code=123456&remember=1"

# 探测通行密钥可用性（公开）
curl http://your-host/api/index.php?m=passkey&a=status

# 管理员：列出在线会话（需后台登录态 Cookie）
curl -b "admin_sid=..." http://your-host/api/index.php?m=admin&a=session_list&kw=alice&page=1

# 管理员：安全审计概览（需后台登录态 Cookie）
curl -b "admin_sid=..." http://your-host/api/index.php?m=admin&a=audit_stats
```
