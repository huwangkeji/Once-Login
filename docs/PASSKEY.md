# 通行密钥（Passkey / WebAuthn）

通行密钥让用户用指纹、面容、PIN 或安全密钥完成登录，无需密码与短信验证码，
可天然抵御钓鱼、撞库与密码泄露。本模块基于 W3C WebAuthn Level 2 实现，
底层使用 `lbuchs/webauthn` 库（`app/Core/WebAuthn/`）。

- 模块入口：`public/api/index.php?m=passkey&a={动作}`
- 服务层：`app/Service/PasskeyService.php`
- 数据层：`app/Model/PasskeyModel.php`（表 `uic_user_passkeys`、`uic_webauthn_challenges`）
- 前端模块：`public/assets/passkey.js`
- 登录页：`app/View/portal/login.php`；管理页：`app/View/portal/security.php`

---

## 一、浏览器与环境约束（必读）

WebAuthn 有两条**浏览器强制**约束，服务端无法绕过：

| 约束 | 说明 | 不满足的后果 |
| --- | --- | --- |
| RP ID 必须是**合法域名** | 不能是 IP 字面量：`127.0.0.1`、`192.168.x.x`、`[::1]` 均非法 | 浏览器抛 `SecurityError: This is an invalid domain.` |
| 页面必须处于**安全上下文** | `https://` 或 `http://localhost`（含任意端口） | 浏览器拒绝 `navigator.credentials.*` |

因此本地联调请使用 `http://localhost:<端口>` 访问，不要使用 `http://127.0.0.1:<端口>`；
生产环境请使用已配置 HTTPS 的域名。

> 用 IP 访问时系统会自动降级：安全中心页显示黄色警示并**禁用**「添加通行密钥」按钮，
> 登录页隐藏通行密钥入口，接口返回中文原因，
> 见 [三、`passkey_status`](#三接口说明) 的 `domain_issue` 字段与 [六、常见问题](#六常见问题)。

**支持的浏览器**：Chrome / Edge 108+、Safari 16+、Firefox 122+（需系统具备平台认证器或外接安全密钥）。

---

## 二、后台配置项

设置中心 → 安全与验证 → 通行密钥：

| 键 | 类型 | 默认 | 说明 |
| --- | --- | --- | --- |
| `passkey_enabled` | bool | `0` | 总开关。关闭时登录页不出现入口、安全中心不显示管理卡片 |
| `passkey_rp_id` | string | 空 | RP ID（域名）。**留空自动从站点地址推导**；必须是合法域名，**禁止填 IP** |
| `passkey_rp_name` | string | 空 | 显示名称，留空使用 `site_name`，展示在浏览器密钥选择弹窗 |
| `passkey_uv` | enum | `preferred` | 用户验证要求：`required` / `preferred` / `discouraged` |
| `passkey_timeout` | int | `120` | 挑战有效期（秒） |
| `passkey_max_per_user` | int | `10` | 每用户最多密钥数，超出返回业务错误 |

> RP ID 一旦产生密钥就不可变更——更换域名后旧密钥全部失效，需用户重新注册。

---

## 三、接口说明

统一入口：`POST|GET /api/index.php?m=passkey&a={动作}`
成功返回 `{"code":0,"message":"ok","data":{...}}`；失败 `code` 非 0，见 `message`。

### 1. `status` — 功能与访问域探测（公开）

请求参数：无。

| 返回字段 | 类型 | 说明 |
| --- | --- | --- |
| `enabled` | int | `1` 已启用 |
| `rp_id` | string | 当前生效的 RP ID |
| `domain_ok` | int | `1` 当前访问域可用于通行密钥；`0` 不可用于（IP 访问 / 非 HTTPS / 域不匹配） |
| `domain_issue` | string | 不可用原因码：`ip`、`insecure`、`host`、`ip_rpid`、`mismatch`、`rp_empty`；可用时为空串 |
| `domain_reason` | string | 中文原因 |
| `domain_hint` | string | 中文修复指引 |

```bash
curl http://your-host/api/index.php?m=passkey&a=status
```

```json
{
  "code": 0, "message": "ok",
  "data": {
    "enabled": 1, "rp_id": "auth.example.com",
    "domain_ok": 1, "domain_issue": "", "domain_reason": "", "domain_hint": ""
  }
}
```

用 IP 访问时：

```json
{
  "code": 0, "message": "ok",
  "data": {
    "enabled": 1, "rp_id": "127.0.0.1", "domain_ok": 0, "domain_issue": "ip",
    "domain_reason": "当前通过 IP 地址（127.0.0.1）访问，浏览器不允许在 IP 上使用通行密钥…",
    "domain_hint": "本机调试请改用 http://localhost 打开本站；线上请使用已配置 HTTPS 的真实域名。"
  }
}
```

### 2. `register_options` — 获取注册挑战（需登录）

参数：无。返回：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `tx` | string | 注册事务号，完成时回传 |
| `options` | object | `PublicKeyCredentialCreationOptions`（`challenge`/`user.id` 等为 base64url 字符串） |

约束：`residentKey=required`（可发现凭证，支持无用户名登录）、`attestation=none`（不收集设备证明）、
`excludeCredentials` 自动排除本人已有凭证避免重复注册。

> 域不可用时直接返回中文错误，不下发挑战（浏览器此时必然会失败）。

### 3. `register_finish` — 完成注册（需登录）

| 参数 | 必填 | 说明 |
| --- | --- | --- |
| `tx` | 是 | 上一步的事务号 |
| `client_data` | 是 | base64url(`response.clientDataJSON`) |
| `attestation` | 是 | base64url(`response.attestationObject`) |
| `label` | 否 | 备注名（如 MacBook），最长 60 字符 |

返回：`{"code":0,"message":"通行密钥已添加","data":null}`。

### 4. `auth_options` — 获取认证挑战（公开）

参数：无（已登录时会自动带上本人凭证列表，未登录则为发现式流程）。
返回 `{tx, options}`（`PublicKeyCredentialRequestOptions`）。

### 5. `auth_finish` — 完成认证并登录（公开）

| 参数 | 必填 | 说明 |
| --- | --- | --- |
| `tx` | 是 | 上一步事务号 |
| `credential_id` | 是 | base64url(`rawId`) |
| `client_data` | 是 | base64url(`response.clientDataJSON`) |
| `authenticator_data` | 是 | base64url(`response.authenticatorData`) |
| `signature` | 是 | base64url(`response.signature`) |
| `remember` | 否 | `1` 记住登录 |
| `app_id` | 否 | 由 SSO 跳转过来时携带 |

返回：`{"code":0,"message":"登录成功","data":{...}}`，登录成功后写入会话 Cookie。
该接口受 IP 策略（`requireIpAllowed('login')`）保护。

### 6. `list` — 密钥列表（需登录）

返回 `data.items[]`：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `id` | int | 主键，重命名/删除使用 |
| `label` | string | 备注名 |
| `aaguid` | string | 认证器厂商标识 |
| `sign_count` | int | 签名计数（用于克隆检测） |
| `backup_eligible` / `backup_state` | int | 是否可备份 / 是否已同步（iCloud、Google 密码管理器等） |
| `created_at` / `last_used_at` | int | 时间戳 |
| `created_text` / `last_used_text` | string | 友好时间文本 |

### 7. `rename` — 重命名（需登录）

参数：`id`（int）、`label`（string，≤60 字符）。

### 8. `delete` — 删除（需登录）

参数：`id`（int）。删除后该设备无法再用通行密钥登录。

### 错误码

| code | 场景 |
| --- | --- |
| `401` | 未登录（除 `status`/`auth_options`/`auth_finish` 外均需登录） |
| `406` | 验证码/IP 策略拦截（`auth_finish`） |
| `500` | 通行密钥未启用、RP 域不可用、挑战过期、数量达上限、签名校验失败 |

---

## 四、接入步骤

### 第 1 步：后台开启并确认域名

设置中心打开 `passkey_enabled`，确认 `passkey_rp_id` 为当前站点的**域名**
（留空自动推导）。随后访问 `/api/index.php?m=passkey&a=status`，
确认 `domain_ok = 1`（为 `0` 时按 `domain_hint` 修正访问地址）。

### 第 2 步：登录页加载前端模块

```html
<script src="/assets/passkey.js?v=20261015-pk"></script>
<script>
  // 探测：浏览器支持 且 当前访问域可用
  if (Passkey.isSupported()) {
    document.getElementById('passkeyBtn').style.display = '';
  }

  // 条件 UI：用户聚焦账号框时浏览器自动弹出已保存的通行密钥
  Passkey.conditionalMediation({ remember: true, onSuccess: afterLogin });
</script>
```

同时给账号输入框加 `autocomplete="username webauthn"`，浏览器才会触发自动填充
（页面已按访问域自动判断，IP 访问时降级为 `username`）。

### 第 3 步：注册与登录

```js
// 注册（用户在安全中心点击「添加通行密钥」，需已登录）
Passkey.register({ label: 'MacBook' })
  .then(res => { if (res.code === 0) console.log('已添加'); })
  .catch(err => alert(err.message));     // 已中文化，非浏览器英文原报错

// 登录（无需输入用户名）
Passkey.auth({ remember: true })
  .then(res => { if (res.code === 0) location.href = '/'; })
  .catch(err => alert(err.message));
```

### 前端 API 一览

| 方法 | 说明 |
| --- | --- |
| `Passkey.isSupported()` | 浏览器支持 **且** 当前访问域可用 |
| `Passkey.isPlatformAvailable()` | 平台认证器（Touch ID / Windows Hello）是否可用 |
| `Passkey.domainIssue()` | 返回域问题码：`ip` / `insecure` / `host`，可用时 `null` |
| `Passkey.domainMessage()` | 域问题的中文说明，可用时返回空串 |
| `Passkey.register({label})` | 注册新密钥（需登录） |
| `Passkey.auth({remember, app_id})` | 通行密钥登录 |
| `Passkey.conditionalMediation({...})` | 条件 UI 自动填充 |
| `Passkey.list()` / `rename(id,label)` / `delete(id)` / `status()` | 管理与状态查询 |

底层错误已统一翻译：`SecurityError` → 中文域名说明；
`NotAllowedError` / `AbortError` → 「操作已取消或超时（未完成设备验证）」；
`InvalidStateError` → 「该设备上已存在此网站的通行密钥」。

---

## 五、服务端集成示例（PHP）

```php
<?php
// 探测通行密钥是否可用（建议放在登录页渲染前，决定是否输出入口）
$api  = 'https://auth.example.com/api/index.php';
$info = json_decode(file_get_contents($api . '?m=passkey&a=status'), true);
$ok   = ($info['code'] ?? -1) === 0
     && (int)($info['data']['enabled'] ?? 0) === 1
     && (int)($info['data']['domain_ok'] ?? 0) === 1;

if (!$ok) {
    error_log('通行密钥不可用：' . ($info['data']['domain_reason'] ?? '未启用'));
}
```

完整可运行示例见 `sdk/examples/passkey.php`。

---

## 六、常见问题

| 现象 | 原因 | 处理 |
| --- | --- | --- |
| 点击添加后提示 `This is an invalid domain.` | 用 IP 访问（含 `127.0.0.1`），浏览器拒绝 IP 作为 RP ID | 改用 `http://localhost` 或 HTTPS 域名；页面会提前提示并禁用按钮 |
| 页面显示「浏览器不支持通行密钥」 | 浏览器版本过低，或访问域不可用且服务端未识别 | 升级 Chrome/Edge/Safari；确认 `passkey/status` 的 `domain_issue` |
| 换域名后所有密钥失效 | 通行密钥永久绑定 RP ID | 换域前先通知用户重新注册；RP ID 变更属于不可逆操作 |
| 提示「已达通行密钥数量上限」 | 超过 `passkey_max_per_user` | 调大该值或先删除旧密钥 |
| 无感验证/验证码与通行密钥冲突 | 两者独立，通行密钥登录不走验证码 | 无需处理；`auth_finish` 只受 IP 策略约束 |
