# 管理员找回密码（后台「忘记密码？」）

后台登录页密码框下方提供「忘记密码？」入口，管理员可用**预留邮箱 + 邮件验证码**自助重置密码。

## 一、组成

| 层 | 位置 |
| --- | --- |
| 入口 | `app/View/admin/login.php` — `#pwdField` 内、密码框下方右对齐的 `.login-forgot` 链接 → `admin.php?c=findpwd` |
| 路由 | `public/admin.php` — `case 'findpwd'`（已加入**未登录白名单**，用 `layout/auth` 居中布局） |
| 页面 | `app/View/admin/findpwd.php` — 两步式：① 账号 + 邮箱 + 验证码 ② 新密码 |
| 服务 | `app/Service/AdminResetService.php` — 发送/校验/重置全部逻辑与频控 |
| 接口 | `public/api/index.php` — `admin/findpwd_send`、`admin/findpwd_reset` |
| 数据 | `admins.email` 字段（`data/schema.sql` + `AdminModel::ensureEmailColumn()` 幂等迁移） |
| 设置 | `admin_findpwd_enabled`（总开关）、`admin_findpwd_debug`（联调回显） |

## 二、启用条件（三者同时满足才出现可用表单）

1. `admin_findpwd_enabled = 1`（设置中心 → 安全与验证 → 管理员找回密码）
2. 站点已开启**邮件服务**（`mail_enabled = 1`），或处于本地联调（见下）
3. 该管理员在后台「管理员」页已填写**邮箱**

任一不满足时：
- 开关关闭 → 登录页连入口一起隐藏；
- 邮件未开启 / 无人设置邮箱 → 找回页显示「暂不支持在线自助找回」+ 原因 + 对外联系邮箱（`site_contact_email`），**不放假表单**。

## 三、安全策略（后台账号权限极高，比前台找回更严）

- **账号 + 邮箱双重匹配**：任一不符统一回「账号或邮箱不匹配」，不区分账号是否存在，防账号枚举。
- **验证码默认绝不回显**。仅当 `debug` 与 `admin_findpwd_debug` **同时开启**才在页面回显，供本地联调；两者默认均为 0，生产环境严禁开启（否则存在后台被接管风险）。
- **频控**：同一邮箱 60 秒 1 次 / 1 小时 5 次；同一 IP 1 小时 20 次。
- **人机验证**：复用 `admin_login_captcha` 开关；无感验证信任凭证（`uic_passive_trust`）可跨页放行。
- **口令强度**：不弱于 8 位且必须同时含字母与数字；与历史 5 次密码不得重复（与后台改密同一策略）。
- **强制下线**：重置成功后递增 `admin_sess_ver_<id>`，该管理员在其它设备的后台会话在下一次请求时失效；同时清除其登录失败锁定。
- **审计**：发送、校验失败、重置成功均写 `logs`（type=`admin`）。

## 四、接口

```
POST /api/index.php   m=admin&a=findpwd_send
  username, email [, captcha_token, captcha_answer]

POST /api/index.php   m=admin&a=findpwd_reset
  username, email, code, new_password, confirm
```

`findpwd_send` 为公开接口，靠图形验证码 + IP 访问策略把关；`findpwd_reset` 受验证码用后即焚与 5 次尝试上限保护。

## 五、测试

```bash
php  tools/admin_findpwd_test.php        # 服务层回归 27 断言
node tools/admin_findpwd_e2e_test.js     # 浏览器端到端 22 断言（需先 php -S 127.0.0.1:8099 -t public）
```

E2E 覆盖：入口文案/位置/对齐、既有登录流程未受影响、跳转、获取验证码、弱口令被拒、重置闭环（新密码可登录 / 旧密码失效）、开关关闭时入口消失且页面降级。
