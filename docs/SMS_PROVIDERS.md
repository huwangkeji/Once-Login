# 短信验证码网关 · 服务商接入与配置教程

统一身份认证中心（UIC）的短信网关采用 **可插拔服务商架构**：

- 新增服务商**只需**新增一个 Provider 类并在注册表登记，**核心发送逻辑零改动**；
- 服务商通过**配置项**动态启用/切换，无需改代码、无需发版；
- 公共能力（验证码有效期、发送频率限制、模板变量）与各服务商私有参数**分离存储**；
- 密钥等敏感信息支持 **环境变量注入**，可做到不落库。

---

## 一、架构总览

```
业务层  VerifyModel / SmsSender（保持原调用签名）
            │
            ▼
       SmsGateway                 ← 核心发送逻辑（只认接口，不认具体服务商）
         ├─ 公共配置解析（有效期 / 频率限制 / 模板变量）
         ├─ 频率限制校验
         ├─ 调度策略（priority / roundrobin / failover）
         └─ 依次尝试 → 任一成功即返回
            │
            ▼
   SmsProviderRegistry            ← 可插拔注册中心
            │
            ▼
 SmsProviderInterface
   └─ AbstractSmsProvider         ← 配置解析 + 环境变量注入 + HTTP(严格SSL)
        ├─ TencentSmsProvider     腾讯云
        ├─ SmsBaoSmsProvider      短信宝
        ├─ AliyunSmsProvider      阿里云
        ├─ HuaweiSmsProvider      华为云
        └─ （你的新服务商）
```

| 文件 | 职责 |
|---|---|
| `app/Core/Sms/SmsProviderInterface.php` | 服务商契约：`key()` `name()` `fields()` `send()` `doc()` |
| `app/Core/Sms/AbstractSmsProvider.php` | 配置解析（含环境变量注入）、凭据校验、密钥掩码、HTTP |
| `app/Core/Sms/SmsProviderRegistry.php` | 注册表，`register()` 为新增服务商的唯一入口 |
| `app/Core/Sms/SmsGateway.php` | 发送编排：公共配置、频率限制、调度策略 |
| `app/Service/SmsSender.php` | 兼容门面，转发到网关（业务层调用不变） |

---

## 二、配置模型

配置分两类存放，均通过 `settings` 表（组 `sms`）覆盖：

### 2.1 公共配置 `sms_common`（与服务商无关）

| 参数 | 默认值 | 含义 |
|---|---|---|
| `code_ttl` | `600` | 验证码有效期（秒） |
| `send_interval` | `60` | 同一手机号两次发送的最小间隔（秒），防刷 |
| `daily_limit` | `10` | 同一手机号自然日发送上限 |
| `ip_daily_limit` | `30` | 同一 IP 自然日发送上限 |
| `template_var` | `code` | 模板变量名，如 `code`（腾讯云按位置参数传递） |
| `strategy` | `failover` | 调度策略 |

另有三个顶层开关：

| 键 | 含义 |
|---|---|
| `sms_enabled` | 短信总开关 |
| `sms_provider` | 主服务商（priority 策略下优先使用） |
| `sms_code_style` | 验证码样式：`digits`(6位数字) / `letters`(4位字母) / `mixed`(4位数字字母) |

### 2.2 服务商私有配置 `sms_providers`

一段 JSON，按服务商分段，每段含 `enabled`、`priority` 与该服务商自有字段：

```json
{
  "tencent": {
    "enabled": 1,
    "priority": 10,
    "secret_id": "", "secret_key": "",
    "sdk_app_id": "1400000000", "sign": "我的应用", "template_id": "1234567"
  },
  "aliyun": { "enabled": 1, "priority": 20, "access_key_id": "", "sign_name": "我的应用" }
}
```

- `enabled`：是否启用（0/1）
- `priority`：优先级，**数字越小越优先**
- 其余字段来自各服务商 `fields()` 声明

### 2.3 环境变量注入（敏感信息不落库）

标记为 `secret` 的字段，在配置留空时会回退读取环境变量：

```
UIC_SMS_{服务商}_{字段名}      # 全部大写
```

| 服务商 | 可用环境变量 |
|---|---|
| 腾讯云 | `UIC_SMS_TENCENT_SECRET_ID`、`UIC_SMS_TENCENT_SECRET_KEY` |
| 短信宝 | `UIC_SMS_SMSBAO_PASS` |
| 阿里云 | `UIC_SMS_ALIYUN_ACCESS_KEY_ID`、`UIC_SMS_ALIYUN_ACCESS_KEY_SECRET` |
| 华为云 | `UIC_SMS_HUAWEI_APP_KEY`、`UIC_SMS_HUAWEI_APP_SECRET` |

解析优先级：**数据库配置 → 环境变量 → 旧扁平键（`sms_{provider}_{field}`）→ 默认值**。
旧扁平键保留仅为平滑迁移，新部署建议直接使用 `sms_providers` + 环境变量。

---

## 三、调度策略（`sms_strategy`）

| 策略 | 行为 | 适用场景 |
|---|---|---|
| `failover` | 按 `priority` 升序依次尝试，第一个成功即返回 | **默认**，主备容灾 |
| `priority` | `sms_provider` 指定的主服务商置顶，其余按优先级兜底 | 明确主通道 + 备用 |
| `roundrobin` | 以当日已发送条数为起点轮转起点，再依次兜底 | 多通道分摊配额 |

> 三种策略在**单个服务商失败后都会自动尝试下一个**，区别只在"先试谁"。

---

## 四、服务商配置教程

> 四个服务商均按同一模板编写：资质准备 → 密钥获取 → 开通与报备 → 参数说明 → 完整示例 → 验证 → 错误码排查。

---

### 4.1 腾讯云短信（`tencent`）

#### 4.1.1 账号与资质准备

- 已完成**实名认证**的腾讯云账号（企业实名通过率更高）。
- 准备签名对应的资质：企业请准备营业执照；个人可用已备案网站或公众号后台截图。
- 一个可接收短信的测试手机号。

#### 4.1.2 密钥获取路径

控制台右上角头像 → **访问管理（CAM）** → **密钥管理** → **新建密钥**，得到：

- `SecretId`
- `SecretKey`（**仅在创建时完整可见**，请立即保存）

建议使用子账号并只授予 `QcloudSMSFullAccess`，避免主账号密钥泄露。

#### 4.1.3 服务开通与签名/模板申请

1. 控制台搜索 **「短信 SMS」** → 开通服务。
2. **应用管理 → 创建应用**，得到 `SdkAppId`（形如 `1400000000`）。
3. **国内短信 → 签名管理** → 添加签名（如「我的应用」）→ 提交资质 → 等待审核（通常 2 小时内）。
4. **国内短信 → 正文模板管理** → 添加模板，正文需含位置变量，例如：

   ```
   您的验证码是{1}，5分钟内有效，请勿泄露给他人。
   ```

   记录模板 ID（形如 `1234567`）。

#### 4.1.4 配置项说明

| 参数 | 必填 | 敏感 | 含义 | 示例 |
|---|---|---|---|---|
| `secret_id` | ✅ | ✅ | CAM 密钥 ID | `AKIDz8krbsJ5yKBZQpn74WFkmLPx3EXAMPLE` |
| `secret_key` | ✅ | ✅ | CAM 密钥 Key | `Gu5t9xGARNpq86cd98joQYCN3EXAMPLE` |
| `sdk_app_id` | ✅ | — | 短信应用 SDK AppID | `1400000000` |
| `sign` | ✅ | — | 已审核通过的签名内容（不含【】） | `我的应用` |
| `template_id` | ✅ | — | 正文模板 ID | `1234567` |
| `region` | — | — | 地域，一般保持默认 | `ap-guangzhou` |

#### 4.1.5 完整配置示例

**方式 A：数据库配置（`settings.sms_providers`）**

```json
{
  "tencent": {
    "enabled": 1,
    "priority": 10,
    "secret_id": "AKIDz8krbsJ5yKBZQpn74WFkmLPx3EXAMPLE",
    "secret_key": "Gu5t9xGARNpq86cd98joQYCN3EXAMPLE",
    "sdk_app_id": "1400000000",
    "sign": "我的应用",
    "template_id": "1234567",
    "region": "ap-guangzhou"
  }
}
```

**方式 B：密钥走环境变量（推荐，密钥不入库）**

```json
{
  "tencent": {
    "enabled": 1,
    "priority": 10,
    "secret_id": "",
    "secret_key": "",
    "sdk_app_id": "1400000000",
    "sign": "我的应用",
    "template_id": "1234567"
  }
}
```

```bash
# 服务器环境变量 / .env（确保 Web 进程可读取）
export UIC_SMS_TENCENT_SECRET_ID=AKIDz8krbsJ5yKBZQpn74WFkmLPx3EXAMPLE
export UIC_SMS_TENCENT_SECRET_KEY=Gu5t9xGARNpq86cd98joQYCN3EXAMPLE
```

#### 4.1.6 验证发送是否成功

1. 后台 → 短信服务 → **体检/发送测试验证码** 至你的手机号。
2. 成功响应形如：`短信已通过腾讯云发送（服务商：腾讯云短信）`。
3. 也可在腾讯云控制台 **短信 → 统计分析 → 发送记录** 核对流水；状态为「发送成功」才算到达。

#### 4.1.7 常见错误码与排查

| 错误码 | 含义 | 排查 |
|---|---|---|
| `AuthFailure.SignatureExpire` | 签名过期 | 服务器时间不同步，配置 NTP 校时 |
| `AuthFailure.InvalidSecretId` | 密钥不存在/被禁用 | 核对 SecretId，或到 CAM 重新启用 |
| `InvalidParameterValue.IncorrectPhoneNumber` | 手机号格式错误 | 需带国家码 `+86`（系统已自动拼接） |
| `InvalidParameterValue.TemplateParamSetFormatError` | 模板变量数量不匹配 | 模板变量个数须与模板占位符一致 |
| `LimitExceeded.PhoneNumberThirtySecondLimit` | 30 秒内重复发送 | 放慢频率；调大 `send_interval` |
| `FailedOperation.SignatureIncorrectOrUnapproved` | 签名未审批或不符 | 确认签名已审核且与填写**完全一致** |

---

### 4.2 短信宝（`smsbao`）

#### 4.2.1 账号与资质准备

- 手机号注册的短信宝账号。
- 账户内有可用短信条数（新用户一般有赠送，不足需充值）。
- **无需**企业资质与签名报备，接入最快。

#### 4.2.2 密钥获取路径

官网注册后，进入 **用户中心 → 接口信息**：

- 接口账号 = 注册手机号
- 接口密码 = 登录密码（系统会自动 `md5` 后传输，此处填**明文**）

#### 4.2.3 服务开通与签名/模板申请

1. 注册并登录短信宝官网。
2. 充值或领取免费短信条数。
3. （可选）后台设置常用签名，便于内容合规。
4. 若开启 IP 白名单，需把**服务器出口 IP** 加入后台白名单。

#### 4.2.4 配置项说明

| 参数 | 必填 | 敏感 | 含义 | 示例 |
|---|---|---|---|---|
| `user` | ✅ | — | 短信宝平台账号 | `13800000000` |
| `pass` | ✅ | ✅ | 平台密码（明文，系统自动 md5） | `yourPassword` |
| `sign` | — | — | 内容前缀签名（自动加【】） | `我的应用` |

#### 4.2.5 完整配置示例

```json
{
  "smsbao": {
    "enabled": 1,
    "priority": 30,
    "user": "13800000000",
    "pass": "",
    "sign": "我的应用"
  }
}
```

```bash
export UIC_SMS_SMSBAO_PASS=yourPassword
```

#### 4.2.6 验证发送是否成功

1. 后台体检发送测试验证码。
2. 接口返回 `0` 即**提交成功**（响应体就是状态码，不是 JSON）。
3. 到短信宝后台 **发送记录** 核对是否真正送达。

#### 4.2.7 常见错误码与排查

| 状态码 | 含义 | 排查 |
|---|---|---|
| `0` | 提交成功 | — |
| `30` | 密码错误 | 填的是明文密码（系统自动 md5）；或重置密码 |
| `40` | 账号不存在 | 核对账号是否为注册手机号 |
| `41` | 余额不足 | 充值后再试 |
| `42` | 账户过期 | 联系短信宝续期 |
| `43` | IP 地址限制 | 后台添加服务器出口 IP 白名单 |
| `50` | 内容含敏感词 | 调整签名或正文措辞 |
| `51` | 手机号码不正确 | 校验手机号格式与号段 |

---

### 4.3 阿里云短信（`aliyun`）

#### 4.3.1 账号与资质准备

- 已完成**企业实名**的阿里云账号（个人实名签名审核较严格）。
- 签名对应的资质材料（营业执照、网站备案号或 APP 著作权）。
- 测试手机号。

#### 4.3.2 密钥获取路径

控制台 → **RAM 访问控制** → **用户** → 创建子用户 → **创建 AccessKey**，得到：

- `AccessKeyId`
- `AccessKeySecret`（仅创建时可见）

给子账号授予 `AliyunDysmsFullAccess`（或更细粒度的发送权限）。

#### 4.3.3 服务开通与签名/模板申请

1. 搜索 **「短信服务」** 并开通。
2. **国内消息 → 签名管理** → 添加签名 → 提交资质 → 等待审核。
3. **国内消息 → 模板管理** → 添加模板，变量形如 `${code}`：

   ```
   您的验证码是${code}，5分钟内有效，请勿泄露。
   ```

   记录 **模板 CODE**（形如 `SMS_123456789`）。
4. 为子账号完成短信服务授权。

#### 4.3.4 配置项说明

| 参数 | 必填 | 敏感 | 含义 | 示例 |
|---|---|---|---|---|
| `access_key_id` | ✅ | ✅ | RAM AccessKey ID | `LTAI4GEXAMPLE` |
| `access_key_secret` | ✅ | ✅ | RAM AccessKey Secret | `yourSecret` |
| `sign_name` | ✅ | — | 已审核签名名称（不含【】） | `我的应用` |
| `template_code` | ✅ | — | 已审核模板 CODE | `SMS_123456789` |
| `region` | — | — | RegionId，一般默认 | `cn-hangzhou` |

#### 4.3.5 完整配置示例

```json
{
  "aliyun": {
    "enabled": 1,
    "priority": 20,
    "access_key_id": "",
    "access_key_secret": "",
    "sign_name": "我的应用",
    "template_code": "SMS_123456789",
    "region": "cn-hangzhou"
  }
}
```

```bash
export UIC_SMS_ALIYUN_ACCESS_KEY_ID=LTAI4GEXAMPLE
export UIC_SMS_ALIYUN_ACCESS_KEY_SECRET=yourSecret
```

#### 4.3.6 验证发送是否成功

1. 后台体检发送测试验证码。
2. 成功响应：`短信已通过阿里云发送（服务商：阿里云短信）`。
3. 控制台 **短信服务 → 业务统计 → 发送记录** 查看流水与到达状态。

#### 4.3.7 常见错误码与排查

| 错误码 | 含义 | 排查 |
|---|---|---|
| `InvalidAccessKeyId.NotFound` | AccessKey 不存在 | 核对 AK，或换用有效子账号 AK |
| `SignatureDoesNotMatch` | 签名不匹配 | 检查 Secret 是否正确、参数是否被二次编码 |
| `InvalidDayuStatus.MsgKeyInvalid` | 签名未审批 | 确认签名已审核且名称完全一致 |
| `isv.SMS_TEMPLATE_ILLEGAL` | 模板不合法/未审批 | 检查 `TemplateCode` 与模板状态 |
| `isv.BUSINESS_LIMIT_CONTROL` | 触发流控 | 放慢频率，或申请提升配额 |
| `isv.MOBILE_NUMBER_ILLEGAL` | 手机号非法 | 校验号段与格式 |
| `isv.AMOUNT_NOT_ENOUGH` | 余额不足 | 充值短信套餐包 |

---

### 4.4 华为云短信（`huawei`）

#### 4.4.1 账号与资质准备

- 已完成**企业实名**的华为云账号。
- 签名所需资质（营业执照、网站备案等）。
- 测试手机号。

#### 4.4.2 密钥获取路径

控制台 → **消息&短信 MSGSMS** → **应用管理** → 创建应用 → 进入**应用详情**，查看：

- `APP Key`
- `APP Secret`
- `APP 接入地址`（地域不同地址不同）

#### 4.4.3 服务开通与签名/模板申请

1. 开通 **「消息&短信 MSGSMS」** 服务。
2. **应用管理 → 创建应用**，记录 APP Key / APP Secret / APP 接入地址。
3. **签名管理** → 申请签名（需资质）→ 等待审核。
4. **模板管理** → 创建模板，变量形如 `${1}`：

   ```
   您的验证码是${1}，5分钟内有效，请勿泄露。
   ```

5. （可选）配置 **状态回调地址** 接收发送报告。

#### 4.4.4 配置项说明

| 参数 | 必填 | 敏感 | 含义 | 示例 |
|---|---|---|---|---|
| `app_key` | ✅ | ✅ | 应用 APP Key | `c8RWg3ggEcyd4D3p94bf3Y7x1Ile` |
| `app_secret` | ✅ | ✅ | 应用 APP Secret | `q4Ii87BhST9vcs8wTB6I60VrK` |
| `api_url` | — | — | APP 接入地址（含地域与端口） | `https://rtcsms.cn-north-1.huaweicloud.com:10743/sms/batchSendSms/v1` |
| `signature` | ✅ | — | 已审核签名名称 | `我的应用` |
| `template_id` | ✅ | — | 已审核模板 ID | `8ff55eac1c1b4d0e9d7e6f8a2b3c4d5e` |
| `from` | — | — | 通道号（签名已绑定时可留空） | `8823099xxxxx` |
| `status_callback` | — | — | 状态回调地址 | `https://your.site/api/sms/report` |

#### 4.4.5 完整配置示例

```json
{
  "huawei": {
    "enabled": 1,
    "priority": 40,
    "app_key": "",
    "app_secret": "",
    "api_url": "https://rtcsms.cn-north-1.huaweicloud.com:10743/sms/batchSendSms/v1",
    "signature": "我的应用",
    "template_id": "8ff55eac1c1b4d0e9d7e6f8a2b3c4d5e",
    "from": "",
    "status_callback": ""
  }
}
```

```bash
export UIC_SMS_HUAWEI_APP_KEY=c8RWg3ggEcyd4D3p94bf3Y7x1Ile
export UIC_SMS_HUAWEI_APP_SECRET=q4Ii87BhST9vcs8wTB6I60VrK
```

#### 4.4.6 验证发送是否成功

1. 后台体检发送测试验证码。
2. 成功响应：`短信已通过华为云发送（服务商：华为云短信）`。
3. 控制台 **发送详情** 查询；若配置了 `status_callback`，可在回调里收到最终状态报告。

#### 4.4.7 常见错误码与排查

| 错误码 | 含义 | 排查 |
|---|---|---|
| `000000` | 成功 | — |
| `E000102` | APP Key / Secret 错误 | 核对应用详情中的密钥 |
| `E000103` | 鉴权失败 / 时间偏差 | 检查服务器时间（NTP），WSSE `Created` 需为 UTC |
| `E000108` | 签名未审批 | 确认签名已审核且名称一致 |
| `E000110` | 模板未审批或不存在 | 确认模板 ID 与审核状态 |
| `E000406` | 手机号格式错误 | 需带国家码 `+86` |
| `E000510` | 触发流控 | 降低频率或申请提额 |
| `E000512` | 余额不足 | 充值短信套餐 |

> 若报「网络不可达」，优先检查 `api_url` 的**地域与端口**是否与控制台给出的接入地址一致（华为云接口常带 `:10743`）。

---

## 五、新增服务商文档模板

后续接入新服务商时，复制以下模板补齐即可，保持与上面四节完全一致的章节结构。

```markdown
### 4.x 服务商中文名（`provider_key`）

#### 4.x.1 账号与资质准备
- 需要哪些账号类型、实名/企业资质
- 其他前置条件（测试号码、余额等）

#### 4.x.2 密钥获取路径
控制台具体菜单路径，列出拿到的密钥名称（哪些仅在创建时可见）。

#### 4.x.3 服务开通与签名/模板申请
1. 开通服务
2. 创建应用
3. 申请签名（需什么资质）
4. 申请模板（变量写法示例）

#### 4.x.4 配置项说明
| 参数 | 必填 | 敏感 | 含义 | 示例 |
|---|---|---|---|---|
| `xxx` | ✅ | ✅ | ... | ... |

#### 4.x.5 完整配置示例
（JSON 片段 + 环境变量片段）

#### 4.x.6 验证发送是否成功
1. 后台体检
2. 成功响应样例
3. 控制台/后台核对方式

#### 4.x.7 常见错误码与排查
| 错误码 | 含义 | 排查 |
|---|---|---|
```

### 配套代码清单（新增服务商时同步完成）

1. 新建 `app/Core/Sms/XxxSmsProvider.php`，继承 `AbstractSmsProvider`，实现 `key()` / `name()` / `fields()` / `send()` / `doc()`。
2. 在 `SmsProviderRegistry::defaults()` 增加一行 `'key' => 'XxxSmsProvider'`（或运行时 `SmsProviderRegistry::register()`）。
3. 在本文件追加一节配置教程。
4. （可选）在 `data/schema.sql` 的 `settings` 中补默认行。

**核心发送逻辑（`SmsGateway`）与业务层均无需修改。**
