# 无感验证 · 行为式风险识别引擎（RiskEngine）

> 参考 **极验（GeeTest）**、**腾讯天御（Tiance）**、**Cloudflare Bot Management** 的验证码/风控思路，
> 在原有「无感验证（passive）」基础上构建的可配置、可分级、可扩展的行为式人机识别系统。

---

## 1. 设计思路（对标三家产品）

| 产品 | 核心思想 | 本系统对应实现 |
|------|----------|----------------|
| **极验 GeeTest** | 采集鼠标 / 触摸**轨迹生物特征**（速度、加速度、方向变化、抖动、熵），做滑动轨迹评分 | `RiskEngine::extractFromTrajectory()` 由服务端从原始轨迹**重算**全部特征，前端无法谎报聚合值 |
| **腾讯天御 Tiance** | 多维加权风险评分 + 风险分级（放行 / 二次验证 / 拦截）+ 自适应策略 | 行为 / 环境 / 服务端三类信号按可配置权重加权得 0-100 分，分级 `pass / challenge / block` |
| **Cloudflare** | Bot Score 0-100 按阈值分级（Allow / Challenge / Block）+ 信任凭证跨页无感放行 | 同款分级 + `uic_passive_trust` HMAC 签名 Cookie 实现跨页无缝放行 |

**与旧版无感验证的区别**
- 旧版：前端自报 `{t,mv,ent,touch}`，后端三道常量阈值（可被伪造）。
- 新版：前端上报**原始轨迹 + 环境指纹**，后端重算特征并做多维加权评分；信任凭证签名绑定 IP；全部阈值 / 权重 / 触发条件 / 强度档位可后台配置。

---

## 2. 整体流程

```
 浏览器 (captcha.js : passive)               服务端 (RiskEngine + CaptchaModel)
 ──────────────────────────                ───────────────────────────────────
 issue(passive) ───────────────► 下发 token + 采集参数(collect_ms/max_points)
                                       │
 采集窗口 collect_ms 内：                  │
   监听 mousemove/touchmove                │
   按节流采样轨迹 [t,x,y]                  │
   采集环境指纹(cores/mem/tz/lang/pxr)      │
                                       │
 submitPassive(traj, env) ──────────────► evaluate(answer, ctx)
                                       │   ctx = {ip, tokenAge, ipFreq, serverTz}
                                       │   ① 反伪造：特征自洽校验（拒绝越界/篡改）
                                       │   ② trigger=suspicious 且基线干净 → 直接 pass
                                       │   ③ 服务端从 traj 重算特征
                                       │   ④ 三类信号加权 → 0-100 风险分
                                       │   ⑤ decide() 分级：
                                       │        score ≤ pass      → pass（无感放行）
                                       │        ≤ challenge       → challenge（回退交互式）
                                       │        > block           → block（拦截）
                                       │
  pass  → 显示「环境安全」+ 下发信任 Cookie  │   通过时 RiskEngine::issueTrustCookie(ip)
  challenge → 自动回退 captcha_fallback     │
  block → 显示「访问被拦截」                │   返回 __passive_fail__ / __passive_block__
```

---

## 3. 核心算法：风险评分

### 3.1 特征提取（服务端重算，`extractFromTrajectory`）
从原始轨迹 `[ [t_ms, x, y], … ]` 计算：

| 特征 | 含义 | 说明 |
|------|------|------|
| `duration` | 采集总时长 | 过短（<250ms）即强风险 |
| `points` | 采样点数 | 过少（<5）即视为无真实交互 |
| `jitter` | 曲折度 = 1 − 直线距离/路径长度 | 0=完美直线（机器人），1=极度曲折（噪声脚本） |
| `ent` | 方向熵 | 不同移动方向桶（8 桶）占比，0-1 |
| `dirChanges` | 方向变化次数 | 单调直线方向变化少 |
| `velCv` | 速度变异系数（std/mean） | 匀速 = 机器人特征 |

### 3.2 单信号风险函数（每个返回 0-1，1=最高风险）
行为类：`tooFast` `tooSparse` `lowEntropy` `tooStraight` `tooNoisy` `lowDirChange` `constantVel`
环境类：`envMissing`（缺失硬件并发数 / 设备内存，常见于 headless / 隐私模式）`tzMismatch`（客户端时区与服务端不符）
服务端类：`tokenAge`（令牌刚下发即提交）`ipFreq`（同 IP 短时大量申请验证码）

### 3.3 加权评分与分级
```
行为组得分 bs = 组内信号均值 × 100
环境组得分 es = 组内信号均值 × 100
服务端组得分 ss = 组内信号均值 × 100

score = (wb·bs + we·es + ws·ss) / (wb+we+ws)      // wb/we/ws = 三类权重(%)
decide(score):  score ≤ pass      → pass
               score ≤ challenge → challenge
               score >  block     → block
```
各组内「未知信号」（如缺失速度数据）自动忽略，避免误判。

---

## 4. 可配置项（后台：安全与验证 → 无感验证）

| 配置键 | 类型 | 默认 | 说明 |
|--------|------|------|------|
| `passive_enabled` | bool | 1 | 无感风险引擎总开关；关闭则回退旧版简单阈值 |
| `passive_trigger` | select | `always` | 触发条件：`always` 始终评分；`suspicious` 仅对可疑流量评分（更低摩擦） |
| `passive_verify_intensity` | select | `normal` | 验证强度：`low` / `normal` / `strict` / `custom` |
| `passive_score_pass` | number | 35 | 放行阈值（0-100） |
| `passive_score_challenge` | number | 70 | 挑战阈值；超过则拦截 |
| `passive_score_block` | number | 88 | 拦截阈值 |
| `passive_w_behavior` | number(%) | 60 | 行为信号权重 |
| `passive_w_env` | number(%) | 20 | 环境信号权重 |
| `passive_w_server` | number(%) | 20 | 服务端信号权重 |
| `passive_allowlist` | textarea | 空 | 放行白名单：精确 IP / 通配 `192.168.1.*` / CIDR `10.0.0.0/8`，命中即恒放行 |
| `passive_collect_ms` | number(ms) | 1600 | 前端轨迹采集窗口 |
| `passive_max_points` | number | 120 | 轨迹最大采样点（控制上报体积） |
| `passive_trust_ttl` | number(s) | 3600 | 信任凭证有效期（跨页无感放行） |
| `passive_secret` | password | 空 | 信任凭证 HMAC 签名密钥（建议填随机值；留空用安装级默认值） |

> 强度档位 `low/normal/strict` 直接映射为三组阈值（`custom` 时以下方数字字段为准）。
> 所有项均已纳入 `SettingsSchema` 三级大纲，可在后台「设置中心」直接编辑，亦支持 `Config::get/set`。

### 4.1 配置示例

**示例 A：宽松（低摩擦，适合低风险站点）**
```
passive_verify_intensity = low        # 或直接自定义：pass=50, challenge=85, block=95
passive_w_behavior = 70
passive_w_env = 15
passive_w_server = 15
passive_trigger = suspicious          # 基线干净的直接放行，仅 scrutinize 可疑流量
```

**示例 B：默认（均衡，推荐）**
```
passive_verify_intensity = normal     # pass=35, challenge=70, block=88
passive_w_behavior = 60
passive_w_env = 20
passive_w_server = 20
passive_trigger = always
```

**示例 C：严格（高安全，金融 / 管理后台）**
```
passive_verify_intensity = strict      # pass=20, challenge=55, block=80
passive_w_env = 25                     # 加重设备指纹权重（headless 直接高危）
passive_w_server = 25
passive_trigger = always
passive_allowlist = 10.0.0.0/8         # 仅内网段恒放行，其余一律评分
```

**示例 D：放行策略（企业内网 / 固定出口）**
```
passive_allowlist =
  10.20.0.0/16
  192.168.1.*
  203.0.113.5
```

---

## 5. 放行策略（两类）

1. **IP / CIDR 白名单**：`passive_allowlist` 命中的 IP 恒放行（企业内网、固定办公出口）。
2. **信任凭证（跨页无感放行）**：无感验证通过时，服务端签发 `uic_passive_trust`（HMAC 签名、绑定 IP、带过期时间）写入 HttpOnly Cookie。
   - 同一浏览器在有效期内再次进入登录页，`captcha_card.php` 直接隐藏验证码；
   - 登录网关 `requireCaptchaIfEnabled()` 校验该 Cookie 后跳过验证码，实现真正的「无感」。

---

## 6. 反伪造与已知局限

**已做的加固**
- 聚合特征（移动数、熵、抖动）一律由服务端从**原始轨迹**重算，前端无法谎报；
- 信任凭证 HMAC 签名 + IP 绑定，无法伪造或跨网络复用；
- `validateFeatures()` 拒绝特征越界 / 时长异常（<50ms）等篡改载荷；
- 静止用户（无足够交互）降级为 `challenge` 而非 `block`，避免误杀。

**局限（与极验 / Cloudflare 同源）**
行为式验证码的本质是「提高自动化成本」而非绝对阻断：一个**刻意生成逼真轨迹**的脚本仍可拿到低分通过。
它真正的价值在于——对正常浏览器完全无感，对裸脚本 / headless 直接升级为交互式或拦截。
若需更强对抗，可在此基础上扩展（见第 7 节）：接入设备指纹 SDK、行为 ML 模型、IP 信誉库等。

---

## 7. 扩展指南（如何定制）

| 想扩展的能力 | 改动位置 |
|--------------|----------|
| 新增风险信号（如加速度峰度、轨迹自相似度） | `RiskEngine` 增加 `sigXxx()` + 在 `score()` 的 `$behavior` / `$env` / `$serverSignals` 数组登记 |
| 调整默认阈值常量（FAST_MS/MIN_MOVES…） | `RiskEngine` 类常量 |
| 新增可配置项（开关 / 权重 / 阈值） | `SettingsSchema.php`（passive 分组）加入定义 → 运行 `php tools/migrate_settings.php --apply` → 在 `RiskEngine::cfg()` 读取 |
| 新的触发条件 | `RiskEngine::evaluate()` 中 `passive_trigger` 分支 + `baselineClean()` |
| 前端采集更多维度（陀螺仪 / 压力 / 多指） | `captcha.js` 的 `collectEnv()` / `startPassive()` 的 `onMove()`，并服务端 `extractFromTrajectory()` 解析 |
| 接入设备指纹 / ML / IP 信誉 | `RiskEngine` 的 `score()` 或新增信号类，调用外部服务后并入评分 |

---

## 8. 测试

- 单元（算法 / 评分 / 分级 / 白名单 / 信任凭证）：`php tools/passive_risk_test.php`（27 项断言，全绿）
- 集成：因无感验证嵌入现有被动样式，原有 `slider_e2e_test.js`、`captcha_feedback_test.js` 不受影响；
  切换 `captcha_style=passive` 后可用浏览器走完整 issue→采集→评分→分级流程。
