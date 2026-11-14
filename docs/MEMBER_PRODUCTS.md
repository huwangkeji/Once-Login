# 会员中心产品展示（member_products）

在会员中心（个人资料 / 账号安全）左侧栏展示本站点对外提供的产品入口磁贴，
管理员可在后台「系统 → 产品展示」维护，前台自动按排序与启用状态渲染。

- 数据层：`app/Model/MemberProductModel.php`（表 `uic_member_products`）
- 后台页面：`app/View/admin/products.php`，路由 `admin.php?c=products`（RBAC `manage:system`）
- 前台展示：`app/View/layout/member.php` → `.mc-side .mc-products`
- 接口：`public/api/index.php?m=admin_product&a={动作}`

---

## 一、数据模型

表名 `uic_member_products`（`Database::TABLES` 已加入前缀白名单）：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `id` | int | 主键 |
| `name` | string | 产品名称（必填，≤60 字符） |
| `url` | string | 跳转地址（`target="_blank"` + `rel="noopener noreferrer"`） |
| `description` | string | 说明，作为磁贴 `title` 悬浮提示 |
| `icon` | string | 图标键，见下表，默认 `link` |
| `sort_order` | int | 排序值，越小越靠前 |
| `enabled` | int | `1` 展示 / `0` 隐藏 |
| `created_at` / `updated_at` | int | 时间戳 |

### 可用图标键（背景色由 `MemberProductModel::iconColor()` 固定映射）

| 键 | 含义 | 底色 | 键 | 含义 | 底色 |
| --- | --- | --- | --- | --- | --- |
| `link` | 链接 | `#2563eb` | `mail` | 邮件 | `#ef4444` |
| `doc` | 文档 | `#0ea5e9` | `shield` | 安全 | `#10b981` |
| `chat` | 会话 | `#22c55e` | `star` | 收藏 | `#f59e0b` |
| `store` | 商城 | `#f59e0b` | `heart` | 关注 | `#ec4899` |
| `tool` | 工具 | `#64748b` | `globe` | 站点 | `#0ea5e9` |
| `cloud` | 云服务 | `#3b82f6` | `code` | 开发 | `#8b5cf6` |

图标为服务端拼装的内联 SVG（`MemberProductModel::iconSvg()`），不依赖字体与 CDN。

### 迁移说明

表由 `MemberProductModel::ensureSchema()` 懒迁移创建；若历史库存在**无前缀**的
`member_products` 表，会自动 `RENAME` 到当前前缀后再建表，保证与全库 `uic_` 前缀一致。

---

## 二、后台管理

入口：管理后台 → 系统 → 产品展示（`admin.php?c=products`），需 `manage:system` 权限。

支持：新增 / 编辑（名称、地址、说明、图标、排序、启用）/ 删除 / 一键启停，
列表按 `sort_order` 升序展示。无数据时会员中心显示「暂无展示产品」。

---

## 三、接口说明

统一入口：`POST /api/index.php?m=admin_product&a={动作}`
**全部接口需要后台登录会话 + `manage:system` 权限**，否则返回 `401` / `403`。

### 1. `list` — 产品列表

参数：无（返回全部，含未启用）。

```json
{"code":0,"message":"ok","data":{"items":[
  {"id":1,"name":"开放平台","url":"https://...","description":"...",
   "icon":"link","sort_order":10,"enabled":1,"created_at":1791990000,"updated_at":1791990000}
]}}
```

### 2. `create` — 新增产品

| 参数 | 必填 | 默认 | 说明 |
| --- | --- | --- | --- |
| `name` | 是 | — | 名称，空则报错「产品名称不能为空」 |
| `url` | 否 | `''` | 跳转地址 |
| `description` | 否 | `''` | 说明 |
| `icon` | 否 | `link` | 图标键，非法值回退 `link` |
| `sort_order` | 否 | `0` | 排序值 |
| `enabled` | 否 | `1` | `1`/`0` |

返回：`{"code":0,"data":{"id":<新ID>},"message":"已新增"}`。

### 3. `update` — 编辑产品

参数同上，额外需要 `id`（int，`<=0` 返回「参数错误」）。
返回：`{"code":0,"data":{"id":<ID>},"message":"已保存"}`。

### 4. `toggle` — 启停

参数：`id`（int）、`enabled`（`1`/`0`）。
返回：`{"code":0,"message":"已启用"|"已禁用"}`。

### 5. `delete` — 删除

参数：`id`（int）。返回：`{"code":0,"message":"已删除"}`。

### 错误码

| code | 场景 |
| --- | --- |
| `401` | 未登录后台会话 |
| `403` | 缺少 `manage:system` 权限 |
| `500` | 参数错误（如 `id<=0`、`name` 为空） |

---

## 四、接入步骤

1. 后台 → 系统 → 产品展示，点击「新增产品」，填写名称、跳转地址与说明；
2. 选择图标与排序值，保存后设为「启用」；
3. 打开会员中心任意页面，左侧栏「<站点名>产品」区域即出现磁贴，点击新窗口打开；
4. 需要下线时点击「禁用」，无需删除（保留历史配置）。

服务端集成示例见 `sdk/examples/admin_product.php`（走后台会话 Cookie 鉴权）。

---

## 五、回归测试

```bash
php tools/member_product_test.php
```

覆盖页面渲染、表前缀一致性、五个接口的 CRUD、前台展示与后台页面；
测试会先快照真实产品数据，结束后原样恢复（含原 `id`），属非破坏性测试。
