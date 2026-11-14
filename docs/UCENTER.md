# UCenter 对接说明

统一身份认证中心通过 UCenter 客户端协议与 UCenter 服务端通信，账号主数据统一存放在 UCenter。

---

## 一、协议基础

对接遵循 Discuz! UCenter 1.5/1.6 通信规范：

1. **authcode 加解密**：`app/Core/Authcode.php` 实现与 `uc_client` 完全一致的 `ENCODE/DECODE`，用于加密 `input` 参数与通信内容。
2. **xml_serialize / xml_unserialize**：`app/Core/XmlKit.php` 负责把数组与 XML 互转（UCenter 接口入参为 `input=加密串`，解码后为 `module=xxx&method=xxx&arg1=...` 形式的序列化数据）。
3. **请求方式**：POST 提交 `input` / `appid` / `inajax` 三个字段；返回为 XML 或纯加密串，按接口约定解析。
4. **通信密钥**：`uc_key` 必须与 UCenter 后台「应用管理」中本应用配置的**通信密钥完全一致**，否则解密失败。

---

## 二、驱动模式（Driver）

系统内置两种驱动，由 `config/config.php` 的 `uc_driver`（或运行时 `settings` 表 `uc_driver`）切换：

| 驱动 | 说明 | 适用场景 |
| --- | --- | --- |
| `mock` | 本地模拟 UCenter，账号写入主库 `uc_members` 表（MySQL） | 没有真实 UCenter 环境时开发/演示；默认开启 |
| `http` | 通过 HTTP 对接真实 UCenter Server（curl / fsockopen 兜底） | 生产环境 |

> 默认 `uc_driver = mock`，可直接跑通注册/登录/找回/SSO 全流程。上线前在「UCenter 配置」中将驱动切到 `http` 并填写真实地址与密钥。

### 切换步骤（后台）
1. 登录后台 → UCenter 配置；
2. 驱动选 `http`，填写 `UCenter 地址`（如 `http://127.0.0.2/uc_server`）、`服务器 IP`（域名解析异常时直连，可留空）、`通信密钥`、`本应用 AppID`、超时；
3. 点「连接测试」确认返回「连接正常」，再点「一键体检」验证 注册/登录/改密/改资料/查询 各接口。

---

## 三、客户端门面

`app/Core/UCenterClient.php` 是统一门面，自动选择驱动，并记录每次通信到 `uc_calls` 表（排错友好）。高层接口：

- `checkName($username)` / `checkEmail($email)` — 唯一性校验
- `register($username, $password, $email, $mobile='')` — 注册，返回 UID
- `login($account, $password)` — 登录校验，返回 `[uid, ...]`
- `edit($username, $oldpwd, $newpwd, $email, $ignoreOld=0)` — 改密/改资料
- `getUser($account)` — 取用户基础资料
- `synlogin($uid)` / `synlogout()` — 同步登录/退出（跨应用）
- `ping()` / `healthCheck()` — 连通性与多接口体检

调用示例（PHP）：

```php
require 'app/bootstrap.php';
$uc = new UCenterClient();
$ping = $uc->ping();            // ['ok'=>true,'message'=>'']
if ($ping['ok']) {
    $uid = $uc->register('alice', 'secret123', 'alice@demo.com');
}
```

---

## 四、关键配置项（config/config.php 的 `ucenter` 数组）

```php
'ucenter' => [
    'driver'   => 'mock',        // mock | http
    'enabled'  => true,
    'url'      => 'http://127.0.0.2/uc_server',
    'ip'       => '',            // 可留空
    'key'      => 'uccenterkey2026abcdef', // 必须与 UCenter 端一致
    'appid'    => 2,
    'connect'  => 'post',        // post | mysql
    'charset'  => 'utf-8',
    'timeout'  => 10,
],
```

---

## 五、排错指引

- **解密失败 / 返回空**：优先核对 `uc_key` 与 `uc_appid` 是否和 UCenter 后台一致；确认 `uc_url` 可访问。
- **连接超时**：检查 `uc_url` 网络可达；必要时填 `uc_ip` 直连；调大 `uc_timeout`。
- **接口返回异常码**：在后台「UCenter 配置」的「UCenter 通信记录」中查看原始请求/响应与耗时。
- **本地开发**：保持 `mock` 驱动，账号落在 `data/ucenter_mock.db`，不影响真实环境。

---

## 六、数据一致性约定

- 注册：先经 UCenter 写入主账号，再在本地 `user_profiles` 落扩展资料，UID 以 UCenter 为准。
- 登录：仅向 UCenter 校验密码，本地只管会话（`sessions`）与 SSO 票据。
- 改密/改资料：先改 UCenter 主数据，成功后再同步本地扩展资料（`markSync`）。
- 禁用/封禁：由 UCenter 控制账号状态，本地读取后在 `user` 接口 `status` 字段反映。

---

## 七、本地协议服务端（http 驱动联调）

没有真实 Discuz! UCenter 时，可用自带的**协议兼容服务端**验证 http 驱动的完整网络链路（真实 HTTP + authcode 加解密 + XML 序列化往返），而 mock 驱动是进程内调用、不走网络。

**位置**：`tools/ucserver/index.php`

**启动**：

```bash
php -S 127.0.0.5:8081 -t tools/ucserver
```

**联调步骤**：

1. 启动 UIC（`php -S 127.0.0.5:8080 -t public`）与协议服务端（8081）；
2. 后台 → UCenter 配置：驱动选 `http`，地址填 `http://127.0.0.5:8081`（服务端会自动补 `/index.php`）；
3. 点「连接测试」/「一键体检」，或在后台单接口测试里跑 register / login / getuser / editpwd；
4. 查看协议服务端日志 `data/ucserver_access.log`，可看到**解密后的真实参数**与返回结果，用于核对协议；

   ```
   appid=2 user.login args={"username":"alice","password":"***","isuid":"0"} => [11,"alice","<md5>","alice@demo.com",0]
   ```

5. 也可以查 UIC 侧 `uc_calls` 表（后台「UCenter 通信记录」）看耗时与状态；
6. 联调完毕把驱动切回 `mock`（或填写真实 `uc_url`）。

**已验证的链路**（本机实测全绿，耗时约 3–6ms/次）：

| 用例 | 协议调用 | 结果 |
| --- | --- | --- |
| 连通性 | `user.checkname` | 可达 |
| 注册 | `user.register` | 返回 uid |
| 登录 | `user.login` | 返回 `[uid, 用户名, 密码, 邮箱, 0]`（数组经 XML 往返） |
| 查询 | `user.get_user` | 返回 `[uid, 用户名, 邮箱]` |
| 改密 | `user.edit` | 返回 1，旧密码随即失效 |

**注意**：
- 服务端与 UIC 必须使用**同一通信密钥**（默认 `uccenterkey2026abcdef`），否则解密失败会返回 `-1`；
- 服务端账号逻辑复用 `MockDriver`，数据落在 `data/ucenter_mock.db`，切换驱动后上层行为一致；
- 接真实 UCenter 时，只需把后台的驱动设为 `http`、地址改成真实 `uc_server` 并填对密钥，无需改代码。

## 八、通知接收（UCenter → 应用）

前面七章讲的都是**应用主动调用 UCenter**（`UCenterClient` → `uc_server/index.php`）。
但 UCenter 还有一个**反方向**的通道：UCenter 后台「基本设置 → 通知设置」里的每个开关，
开启后 UCenter 会主动 POST 通知到应用，请求：

```
GET/POST  {应用URL}/api/uc.php?code={urlencode(authcode(query, ENCODE, 通信密钥))}
```

应用**必须返回 `1`**（部分动作需返回数据），否则 UCenter 侧显示「通知失败」，
表现为「UCenter 能连上应用，但通知过不来」。

### 8.1 UCenter 侧需要怎么配

| 配置项 | 值 |
| --- | --- |
| 应用 URL | `http://你的域名/`（指向站点根，不要带 `/api`） |
| 接口文件名称 | `api/uc.php`（留空即默认值） |
| 通信密钥 | 与 UIC 后台「UCenter 配置」里的密钥**完全一致** |

应用侧无需任何额外配置，`public/api/uc.php` 已内置为统一入口，
上传后即可接收通知；通知记录见后台「UCenter 配置 → 通知接收记录」。

### 8.2 覆盖的通知动作

与 UCenter 后台「通知设置」逐项对应，全部已实现并回执成功：

| UCenter 后台开关 | action | 本应用的处理 |
| --- | --- | --- |
| （应用管理→测试通知） | `test` | 直接回执 1 |
| 是否接受用户删除通知 | `deleteuser` | 清理本地资料 / 会话 / Token / Ticket |
| 是否接受用户改名通知 | `renameuser` | 同步更新本地用户名与会话用户名 |
| 是否接受更改用户密码通知 | `updatepw` | 强制下线该用户全部会话（安全要求） |
| 是否接受更新关键字列表通知 | `updatebadwords` | 落地为 `uc_badwords` 配置 |
| 是否接受更新域名解析缓存通知 | `updatehosts` | 落地为 `uc_hosts` 配置 |
| 是否接受更新应用列表通知 | `updateapps` | 按名称匹配并同步本地应用的站点地址 |
| 是否接受更新客户端缓存通知 | `updateclient` | 清配置缓存并记录同步时间 |
| （基础设置→更新缓存） | `updatecache` | 清配置缓存 |
| 是否接受更新用户积分通知 | `updatecredit` | 落地为 `uc_credits` 配置 |
| 是否接受获取用户的某项积分通知 | `getcredit` | 返回该用户积分值（数字） |
| 是否接受向 UCenter 提供积分设置通知 | `getcreditsettings` | 返回 `serialize` 格式的积分设置 |
| 是否接受更新应用积分设置通知 | `updatecreditsettings` | 落地为 `uc_credit_settings` |
| 是否接受添加事件通知 | `addfeed` | 记录事件到日志 |
| 是否接受获取标签通知 | `gettagg` | 返回 `serialize` 空标签集 |
| （用户管理→同步登录） | `synlogin` | 记录（不代替用户建会话，避免越权） |
| （用户管理→同步退出） | `synlogout` | 撤销该用户全部会话 |

未知动作默认**宽容回执成功**（避免 UCenter 升级新增动作后出现通知失败），
可在后台切为严格模式。

### 8.3 后台配置项

后台「UCenter 配置 → 通知接收」提供：

| 配置 | 说明 |
| --- | --- |
| 通知回调地址 | 自动生成，可直接复制填入 UCenter 应用 URL |
| 接收总开关 | 关闭后所有通知仅记录、不落地业务（仍回执成功） |
| 时间戳允许偏差 | 默认 3600 秒；服务器时间不同步时可设为 0 关闭校验 |
| 未知通知动作 | 宽容（回执成功）/ 严格（拒绝） |
| 来源 IP 白名单 | 留空不限制；填了则只接受白名单内的来源 |
| 明文直传 | **默认关闭**。明文参数无签名，可被伪造，仅放行 `test` 类无害动作，仅供调试 |
| 通知动作开关 | 逐项控制是否在本地落地业务，不影响回执 |
| 记录保留条数 | 默认 500，超出自动裁剪 |

### 8.4 自测

```bash
# 项目主自检（含通知接收项）
php tools/selftest.php

# 通知专项自测：覆盖全部动作 + 协议边界
php tools/ucnotify_selftest.php

# 走真实 HTTP 回环（验证网络与协议层）
php -S 127.0.0.5:8099 -t public &
php tools/ucnotify_selftest.php --http=http://127.0.0.5:8099
```

后台「UCenter 配置」页也提供「自测接收」按钮：会用与 UCenter 完全一致的方式
（authcode 加密 `code` 参数）向本站回调地址发一条真实通知，验证
「网络可达 + 密钥一致 + 解密正确 + 回执成功」四件事。

### 8.5 排错

| 现象 | 原因与处理 |
| --- | --- |
| UCenter 显示「通知失败」 | 先用后台「自测接收」按钮判断是本应用还是网络问题 |
| 通知记录里状态为 `rejected` + 「密文解密失败」 | 通信密钥不一致，核对 UCenter 应用配置与 UIC 后台密钥 |
| 状态为 `rejected` + 「时间戳偏差」 | 双方服务器时间不同步，校准时间或把偏差设为 0 |
| 状态为 `ignored` | 总开关或该动作的本地开关被关闭，仅回执未落地业务 |
| 记录里状态是 `ok` 但 UCenter 仍报失败 | 检查应用 URL 是否指向站点根（不是 `/api`），接口文件名是否为 `api/uc.php` |
| 密文里的 `+` 号导致解密失败 | 已处理：入口优先从原始 `QUERY_STRING` 用 `rawurldecode` 提取 `code`，兼容未做 urlencode 的 UCenter |

> 协议细节：`code` 参数经 authcode 解密后是 query string，含 `action` / `time` 及动作相关参数。
> 时间戳偏差超过阈值会被判为重放并拒绝（返回 `Authracation has expiried`）。
