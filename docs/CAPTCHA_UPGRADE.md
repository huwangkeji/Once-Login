# 人机验证体系升级方案：滑块样式修复 + 多验证方式 + 后台可配置

> 版本：v1.1.0 ｜ 适用：统一身份认证中心（UIC）前后台登录、找回密码
> 相关代码：`app/Model/CaptchaModel.php`、`public/assets/captcha.js`、`app/View/partials/captcha_card.php`、`public/assets/app.css`、`app/View/admin/settings.php`

---

## 一、背景与问题定位

原滑块验证码（后台登录页红框区域）存在以下问题：

| # | 问题 | 根因 |
|---|------|------|
| 1 | 移动端滑块圆点（thumb）与轨道偏移、对不齐 | CSS 在 ≤480px 断点把 thumb 改为 40px，但 JS 仍按 18px 计算 `left: calc(pct% - 18px)`，视觉偏心约 10px |
| 2 | 图片加载瞬间卡片高度跳动（CLS） | 舞台无占位比例，`<img>` 加载后高度从 0 撑开 |
| 3 | 触屏拖动偶尔"粘手"、页面跟着滚 | 滑块容器 `touch-action` 策略不完整， thumb 定位方式依赖宽度计算 |
| 4 | 三种登录页（前台/后台/找回密码）各复制一份滑块 JS | 无统一组件，任何样式修复要改三处，易漏 |
| 5 | 验证方式单一 | 仅 slide/digits/mixed，无点选、无感等方式，也无法后台切换 |

---

## 二、总体设计

### 2.1 验证方式矩阵

| 样式 key | 名称 | 交互 | 服务端答案 | 渲染方式 | 依赖 |
|---|---|---|---|---|---|
| `slide` | 滑动拼图 | 拖动滑块对齐缺口 | 缺口左缘 x（容差 ±10px） | GD → PNG data-uri | GD 扩展 |
| `click` | 文字点选 | 按提示顺序点击 4 个文字 | 有序下标 `2,0,3,1`（哈希） | **服务端拼装 SVG data-uri** | 无（用客户端字体） |
| `icon` | 图标选择 | 点选全部指定图标（2~3 个） | 无序下标集合 `1,4,6`（哈希） | **服务端拼装 SVG data-uri** | 无（纯几何路径） |
| `passive` | 无感验证 | 无操作，静默采集行为评分 | 行为 JSON `{t,mv,ent,touch}` | 不下发图片 | Pointer Events |
| `digits` / `mixed` | 字符输入 | 输入 4 位字符 | 字符（哈希） | GD → PNG data-uri | GD 扩展 |

> **设计要点**：click/icon 的图片由服务端直接生成 SVG 文本（`data:image/svg+xml;base64,...`），文字用系统字体栈、图标用内联路径，**不依赖服务器 CJK 字体**，规避了"服务器缺中文字体导致点选码变成方框"的经典兼容性问题；passive 不下发任何图片。

### 2.2 架构（前后端分离）

```
登录/找回密码视图 (portal/login, admin/login, portal/findpwd)
   └─ include partials/captcha_card.php      # 全模式 DOM 骨架 + <script captcha.js>
        └─ UicCaptcha.init({req, api, show, style, fallback})
             ├─ GET/POST m=captcha&a=issue  → CaptchaModel::issue(style)
             │      ├─ slide/digits/mixed → GD 渲染
             │      ├─ click/icon         → SVG 拼装
             │      └─ passive            → 仅签发票据
             ├─ (passive) m=captcha&a=verify（预检，不消耗）→ passiveScore()
             │      └─ 低分 → 自动 load(captcha_fallback) 无感回退
             └─ 表单提交 captcha_token + captcha_answer
                    └─ 服务端 requireCaptchaIfEnabled / admin_login
                         → CaptchaModel::verify(token, answer, consume=true)
```

- **后端**只认 `token + answer` 两个字段，与样式完全解耦：新增验证方式不需要改任何登录逻辑。
- **前端**统一为 `UicCaptcha` 模块，六个样式共用一套加载/刷新/取值 API（`token` / `answer()` / `ready()` / `reset()`）。

### 2.3 无感验证（passive）流程

1. 签发：`issue('passive')` 只落库 token，不下发图片。
2. 采集：前端监听 `mousemove` / `touchmove` 1.6s，统计耗时 `t`、移动次数 `mv`、方向熵 `ent`（8 个角度桶的覆盖率）、是否触摸设备。
3. 预检：行为数据 POST `captcha&a=verify`（**不消耗 token**），服务端按阈值评分：
   - `t < 600ms` → 拒绝（机器过快）
   - `mv < 5`（触摸设备 `< 3`）→ 拒绝
   - `ent < 25` → 拒绝（轨迹过于机械）
4. 回退：评分未通过 → 前端**无感切换**到 `captcha_fallback` 指定的交互式验证码，用户无感知续操作。
5. 终检：登录提交时服务端再次评分并**消耗 token**（防重放）。

---

## 三、后端修改清单

| 文件 | 修改 |
|---|---|
| `app/Model/CaptchaModel.php` | ① `styles()` 扩展为 6 种；② 新增 `issueClick()/issueIcon()/issuePassive()/passiveScore()`；③ `verify()` 增加 `click/icon/passive` 分支与 `$consume` 参数；④ GD 检查仅对依赖 GD 的样式生效；⑤ `fallbackStyles()` 供后台下拉使用 |
| `public/api/index.php` | `captcha_verify` 改为**不消耗** token（预检语义）；`admin_settings_save` 增加 `captcha_style/captcha_fallback` 合法性校验；两处登录网关把 `__passive_fail__` 转换为用户可读提示 |
| `config/config.php` | 新增 `captcha_fallback => 'slide'`；更新 `captcha_style` 注释 |
| `data/schema.sql` | settings 种子数据新增 `captcha_fallback` 行，更新 `captcha_style` 备注 |

**verify() 答案格式约定**

| 样式 | answer 格式 | 示例 |
|---|---|---|
| slide | 纯数字（拼图块目标左坐标） | `123` |
| digits/mixed | 4 位字符（不区分大小写） | `A3b9` |
| click | 有序下标，逗号分隔 | `2,0,3,1` |
| icon | 全部目标下标，逗号分隔（服务端排序后比对） | `1,4,6` |
| passive | 行为 JSON | `{"t":2100,"mv":42,"ent":75,"touch":0}` |

**安全不变量**：token 单次有效、5 分钟过期、最多 5 次尝试；字符/点选答案均以 `sha256(answer+salt)` 存储，`hash_equals` 常量时间比对；slide 答案强制 `ctype_digit`；passive 评分阈值集中在模型常量（`PASSIVE_MIN_MS/MOVE/ENT`），便于按业务调整。

---

## 四、前端修改清单

| 文件 | 修改 |
|---|---|
| `public/assets/captcha.js` | **整体重写**为统一模块：模式调度（text/slide/pick/passive）、点选网格动态生成、无感采集与自动回退、键盘可达性、加载态统一管理 |
| `app/View/partials/captcha_card.php` | 重构为全模式 DOM：新增 `#capModePick`（点选舞台+网格）、`#capModePassive`（状态条）、卡片底部共用刷新区 `#capRefresh/#capFootHint`；自带 `<script src="assets/captcha.js">` |
| `app/View/portal/login.php`、`admin/login.php`、`portal/findpwd.php` | 删除约 200 行/页的重复滑块脚本，改为 `include` 组件 + `UicCaptcha.init(...)`；提交前 `UicCaptcha.ready()` 校验，失败后 `UicCaptcha.reset()` 刷新 |
| `public/assets/app.css` | 见第五节样式修复 |

**三个登录页的接入代码（模式完全一致）**

```php
<?php
$showCap     = Config::get('login_captcha') ? 1 : 0;          // 后台页用 admin_login_captcha
$capStyle    = (string)Config::get('captcha_style', 'slide');
$capFallback = (string)Config::get('captcha_fallback', 'slide');
$capApi      = $base . '/api/index.php';
?>
<div class="field <?= $showCap ? '' : 'hide' ?>" id="capField">
  <?php $capShow = $showCap ? 1 : 0; include __DIR__ . '/../partials/captcha_card.php'; ?>
</div>
<script>
UicCaptcha.init({
  req: function(){ return App.req.apply(App, arguments); },   // 布局末尾才定义 App，必须惰性求值
  api: '<?= e($capApi) ?>',                                   // 无感预检直连，避免通用 req 弹错误 toast
  show: <?= $showCap ? 'true' : 'false' ?>,
  style: '<?= e($capStyle) ?>',
  fallback: '<?= e($capFallback) ?>'
});
// 提交时：
//   if (!UicCaptcha.ready()) { toast('请完成安全验证'); return; }
//   d.captcha_token = UicCaptcha.token; d.captcha_answer = UicCaptcha.answer();
// 失败后：UicCaptcha.reset();
</script>
```

> 后台登录页额外传 `active: function(){ return step === 1; }`，进入 TOTP 二步验证时自动停用验证码模块。

---

## 五、滑块样式修复明细（对应截图问题）

1. **thumb 居中改为 transform 方案**（核心修复）
   - 旧：`left: calc(pct% - 18px)`，JS 硬编码半宽，断点改宽度后必偏。
   - 新：CSS `left:0; transform:translate(-50%,-50%)`，JS 只写 `left: pct%`；宽度 36px→40px 自适应，`hover/active` 缩放全部走 transform，不再冲突。
2. **舞台占位防跳动**：`.verify-slide-stage{aspect-ratio:300/150}`，图片未加载时卡片高度已确定，消除 CLS；不支持 `aspect-ratio` 的老浏览器由图片自身高度自然兜底。
3. **触摸拖动**：滑块容器保留 `touch-action:pan-y`（横向手势归滑块、纵向仍可滚页）；点选瓦片 `touch-action:manipulation` + `-webkit-tap-highlight-color:transparent`，消除移动端点击蓝框与 300ms 延迟感。
4. **拼图块定位**：按 `left/top` 百分比 + `drop-shadow`，随背景图缩放自动对齐；缺口纵向坐标由服务端 `hole_y` 下发，前端不再"猜"垂直位置。
5. **加载态**：四类模式共用一个 overlay 调度函数，切模/换图/刷新不再出现"两个 loading 叠显示"。
6. **暗色模式与降级**：新增点选/无感组件的 `prefers-color-scheme: dark` 覆盖；`prefers-reduced-motion` 下关闭shake/淡入动画。
7. **可达性**：range 保留原生键盘操作（方向键/Home/End），点选瓦片为真实 `<button>` 带 `aria-label` 与 `:focus-visible` 描边。

---

## 六、后台配置（管理员切换默认验证方式）

路径：**后台 → 系统设置 → 系统基础**（`manage:system` 权限），两项新配置：

| 配置项 | 说明 | 可选值 |
|---|---|---|
| `captcha_style` | 默认验证方式，保存后即时生效（前台登录、后台登录、找回密码共用） | slide / click / icon / passive / digits / mixed |
| `captcha_fallback` | 无感验证评分未通过时自动回退的交互式验证 | slide / click / icon / digits / mixed |

- 存量库无这两行时，设置页按默认值兜底展示，**保存一次即落库**（与既有 `admin_login_captcha` 同机制）。
- 服务端对非法取值直接拒绝保存（`Response::error`），防止把配置写坏后全员无法登录。
- 也可直接用 API：`POST admin&a=settings_save`，`data={"captcha_style":"passive","captcha_fallback":"slide"}`。

**settings 表示例**

```sql
('captcha_style',    '"passive"', 'system', '图形验证码样式: slide/click/icon/passive/digits/mixed', 0),
('captcha_fallback', '"slide"',   'system', '无感验证未通过时回退的验证码: slide/click/icon/digits/mixed', 0),
```

---

## 七、兼容性处理建议

| 场景 | 处理 |
|---|---|
| 服务器无 GD 扩展 | slide/digits/mixed 签发时返回明确错误；**click/icon/passive 不受影响**（纯 SVG/无图）。建议生产环境保留 GD，但配置 `click` 或 `passive` 可在无 GD 主机上运行 |
| 服务器无 CJK 字体 | click 文字点选使用 SVG + 客户端字体栈（Microsoft YaHei/PingFang SC/Noto Sans CJK），**彻底规避** GD 中文方框问题 |
| IE9 / 旧版浏览器 | SVG data-uri 在 `<img>` 中 IE9+ 支持；`aspect-ratio`/`prefers-reduced-motion` 等新特性均为渐进增强，缺失时退回旧布局，不报错 |
| 移动端 | thumb 40px 触摸目标、瓦片 ≥40px、`touch-action` 分区、无感验证对触摸设备放宽移动阈值 |
| 键盘-only 用户 | 无感验证必然评分不足 → 自动回退到交互式验证码，不影响登录 |
| 关闭 JS | 验证码卡片不渲染，表单提交缺 token 被服务端拒绝（与原有行为一致） |
| 老旧 Android 4.x WebView | range 滑块为原生控件兼容性最好；点选/无感为其增强项，可在后台切回 `digits` |
| 安全 | token 单次+5 分钟+5 次尝试不变；新增 passive 预检**不消耗** token，重放仍由终检拦截 |

**升级步骤建议**

1. 部署代码（PHP/JS/CSS 同版本发布，注意 `app.css`/`captcha.js` 已带 `?v=20261013` 版本参数，发布后强刷缓存）。
2. 后台「系统设置」把 `captcha_style` 先设为 `click` 或 `icon` 观察一日，再按需切 `passive`（无感对正常用户零打扰，机器人自动落交互式验证）。
3. 若站点以老旧设备为主，保持 `slide` 或 `digits` 即可——样式修复对旧方式同样生效。

---

## 八、自测结果

- `CaptchaModel` 6 种样式签发/校验桩件测试：**39/39 通过**（含顺序敏感、容差边界、token 消耗、passive 各阈值、配置驱动回退）。
- 5 个视图渲染冒烟测试通过（DOM id 齐全、无旧 id/旧函数残留、设置页下拉选中态正确）。
- `php -l` 全部通过；`node --check captcha.js` 通过。
