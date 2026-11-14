# 设置中心与设置项分类大纲（SettingsSchema）

> 适用版本：2026-10-14 起。后台「系统设置」已升级为「设置中心」，
> 全部配置项按 **模块 → 分组 → 设置项** 三级层级组织，支持搜索与统一校验。

## 1. 概览

| 层级 | 说明 | 落库位置 |
|------|------|----------|
| 模块（一级） | 功能域，如「安全与验证」 | `settings.s_group`（模块 id） |
| 分组（二级） | 模块内的小节，如「人机验证」 | 仅在大纲中定义（页面展示层级） |
| 设置项（三级） | 具体键，如 `captcha_style` | `settings.s_key / s_value` |

- **单一事实来源**：分类、显示名、控件类型、默认值、校验规则全部集中在
  `app/Core/SettingsSchema.php`，后台页面与保存接口共用。
- **不丢项**：大纲未收录的键会自动归入「未分类」模块展示，改版不会隐藏任何配置。
- **可检索**：每个键可带 `keywords`（别名/同义词），支持按名称 / 键名 / 说明模糊搜索。

## 2. 模块清单（9 个模块 / 24 个分组 / 97 项）

| 模块 id | 模块名 | 分组 |
|---------|--------|------|
| `site` | 站点信息 | 品牌与文案 / 主体与备案 / 联系方式 / 页脚与自定义 |
| `account` | 账号与注册 | 注册策略 / 账号生命周期 |
| `security` | 安全与验证 | 登录保护 / 人机验证 / 密码策略 / 访问控制 |
| `sso` | 会话与单点登录 | 凭证有效期 |
| `mail` | 邮件服务 | SMTP 连接 / 发件人 |
| `sms` | 短信服务 | 网关总开关与调度 / 公共策略 / 腾讯云 / 阿里云 / 短信宝 / 华为云 |
| `ucenter` | UCenter 对接 | 连接配置 / 通知接收 |
| `oauth` | 第三方登录 | 通用策略 / 平台配置 |
| `system` | 系统与运维 | 运行模式 / 系统内部键值（只读，如 `admin_totp_enabled_*`） |

## 3. 控件类型（SettingsSchema::T_*）

`bool` 开关 / `select` 枚举 / `number` 数字（可带单位） / `text` 单行 /
`textarea` 多行 / `json` JSON（textarea + 保存时校验） /
`password` 敏感字段（掩码 + 显示切换） / `static` 只读（不参与保存）。

## 4. 保存链路

```
设置中心表单（data-k 全量提交）
  → api admin_settings_save
      → SettingsSchema::normalize()   按类型归一化 + 取值白名单校验
      → IpPolicy::validatePolicy()    IP 策略跨字段校验
      → CaptchaModel::styles()        验证码样式语义校验
      → Config::set(k, v, 模块id, 中文标签)
```

模块 id 直接作为 `s_group` 落库，旧数据用迁移工具一次性归位。

## 5. 迁移与维护

```bash
# 预演（不写库，打印变更计划与分类统计）
php tools/migrate_settings.php

# 实际执行：修正 s_group / 补写 remark / 补建缺失键（带默认值，不覆盖已有值）
php tools/migrate_settings.php --apply

# 仅查看当前分类统计
php tools/migrate_settings.php --stats
```

新增设置项的步骤：

1. 在 `app/Core/SettingsSchema.php` 对应模块 / 分组的 `keys` 中加一行定义；
2. `config/config.php` 补默认值（可选）；
3. `data/schema.sql` 的 settings 种子补一行（新装环境）；
4. 存量环境执行 `php tools/migrate_settings.php --apply`。

## 6. 相关工具

| 工具 | 用途 |
|------|------|
| `tools/migrate_settings.php` | 设置项分类迁移 / 统计 |
| `tools/set_captcha_style.php` | 快速切换默认验证方式（调试用） |
| `tools/slider_geometry_test.js` | 滑块验证码几何对齐回归测试（Playwright） |
| `tools/authbg_test.js` | 登录页动态星空回归测试（Playwright） |
