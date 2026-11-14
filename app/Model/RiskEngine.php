<?php
/**
 * 无感验证 · 行为式风险识别引擎（RiskEngine）
 * =============================================================
 * 设计思路（参考极验 / 腾讯天御 / Cloudflare 验证码）：
 *   1. 极验：采集鼠标 / 触摸轨迹，做「轨迹生物特征」评分（速度、加速度、方向变化、抖动、熵）。
 *   2. 腾讯天御：多维加权风险评分 + 风险分级（放行 / 二次验证 / 拦截）+ 自适应策略。
 *   3. Cloudflare：Bot Score 0-100，按阈值分级（Allow / Challenge / Block），
 *      并辅以服务端信号（IP 频率、令牌时效）与「信任凭证」实现跨页无感放行。
 *
 * 本引擎据此实现：
 *   - 客户端上报「原始轨迹 + 环境指纹」，服务端**重算**所有行为特征（防伪造聚合值）；
 *   - 三类信号（行为 / 环境 / 服务端）按可配置权重加权得到 0-100 风险分；
 *   - 按可配置阈值分级：pass（无感放行）/ challenge（降级交互式验证码）/ block（直接拦截）；
 *   - 放行策略：IP/CIDR 白名单恒放行、信任凭证（HMAC 签名 Cookie）跨页无感放行；
 *   - 全部阈值 / 权重 / 触发条件 / 强度档位均为后台可配置项（见 SettingsSchema）。
 *
 * 防伪造要点：聚合特征（移动数、熵、抖动…）一律由服务端从原始轨迹重算，
 * 客户端无法谎报；信任凭证由服务端 HMAC 签名并绑定 IP 与过期时间，无法伪造。
 */
class RiskEngine
{
    // ---------------- 服务端内建常量（行为判定的经验阈值，非用户阈值） ----------------
    const FAST_MS       = 250;   // 采集总时长低于此值视为「瞬时完成」，强风险
    const MIN_MOVES     = 5;     // 轨迹采样点下限，过少视为无真实交互
    const STRAIGHT_MAX  = 0.08;  // 抖动低于此值 = 机械直线（机器人特征）
    const NOISY_MIN     = 0.85;  // 抖动高于此值 = 随机噪声（脚本特征）
    const DC_RATIO_MIN  = 0.15;  // 方向变化率下限（方向变化/点数）
    const VEL_CV_MIN    = 0.15;  // 速度变异系数下限（过低 = 匀速机器人）
    const TOKEN_AGE_MIN = 120;   // 令牌下发到提交间隔低于此值（ms）视为异常
    const IP_FREQ_MAX   = 8;     // 同 IP 10 分钟内下发的验证码数量上限
    const IP_FREQ_WINDOW = 600;  // 同 IP 频率统计窗口（秒）
    const TRUST_COOKIE  = 'uic_passive_trust';

    // 强度档位 → 风险阈值（pass 上限 / challenge 上限 / block 下限）
    const INTENSITY = [
        'low'    => [50, 85, 95],   // 宽松：大部分放行
        'normal' => [35, 70, 88],   // 默认
        'strict' => [20, 55, 80],   // 严格：少量可疑即挑战 / 拦截
    ];

    /** 读取配置，带默认值兜底（未迁移前亦可工作） */
    private static function cfg($k, $d)
    {
        return Config::get($k, $d);
    }

    /** 签名密钥：优先用后台 passive_secret，缺省退化为安装级常量（建议在后台填写随机值） */
    private static function secret()
    {
        $k = trim((string)self::cfg('passive_secret', ''));
        if ($k !== '') {
            return $k;
        }
        $dbName = 'uic';
        $db = Config::get('db', []);
        if (is_array($db) && !empty($db['dbname'])) {
            $dbName = $db['dbname'];
        }
        return hash('sha256', 'uic-passive-secret:' . $dbName);
    }

    // ============================================================
    //  一、特征提取（服务端从原始轨迹重算，防伪造）
    // ============================================================

    /**
     * 从原始轨迹重算行为特征。
     * @param array $traj  [ [t_ms, x, y], ... ]，t 为相对采集起点的毫秒偏移
     * @return array
     */
    public static function extractFromTrajectory(array $traj)
    {
        $n = count($traj);
        $f = [
            'points'   => $n,
            'duration' => 0,
            'pathLength' => 0,
            'straight' => 0,
            'jitter'   => null,    // 0=直线, 1=极度曲折
            'ent'      => 0,       // 方向熵 0-1
            'dirChanges' => 0,
            'velMean'  => null,
            'velCv'    => null,    // 速度变异系数
            'valid'    => false,
        ];
        if ($n < 2) {
            return $f;
        }
        $xs = []; $ys = []; $ts = [];
        foreach ($traj as $p) {
            if (!is_array($p) || count($p) < 3) {
                continue;
            }
            $ts[] = (float)$p[0];
            $xs[] = (float)$p[1];
            $ys[] = (float)$p[2];
        }
        $m = count($xs);
        if ($m < 2) {
            return $f;
        }
        $f['valid'] = true;
        $f['duration'] = max(0, end($ts) - $ts[0]);

        $path = 0.0;
        $angs = [];
        $dirs = [];
        $buckets = [];
        $prevBucket = -1;
        $dc = 0;
        $velocities = [];
        for ($i = 1; $i < $m; $i++) {
            $dx = $xs[$i] - $xs[$i - 1];
            $dy = $ys[$i] - $ys[$i - 1];
            $seg = hypot($dx, $dy);
            $path += $seg;
            if ($seg > 0) {
                $ang = atan2($dy, $dx);
                $angs[] = $ang;
                $bucket = (int)floor((($ang + M_PI) / (M_PI / 4))) % 8;
                $dirs[$bucket] = 1;
                if ($bucket !== $prevBucket) {
                    if ($prevBucket >= 0) {
                        $dc++;
                    }
                    $prevBucket = $bucket;
                }
            }
            $dt = max(1, $ts[$i] - $ts[$i - 1]);
            $velocities[] = $seg / $dt; // px/ms
        }
        $f['pathLength'] = $path;
        $f['straight'] = hypot($xs[$m - 1] - $xs[0], $ys[$m - 1] - $ys[0]);
        $f['jitter'] = $path > 0 ? (1 - $f['straight'] / $path) : 1.0;
        $f['ent'] = count($dirs) / 8.0;
        $f['dirChanges'] = $dc;

        if (count($velocities) >= 2) {
            $mean = array_sum($velocities) / count($velocities);
            $vars = 0.0;
            foreach ($velocities as $v) {
                $vars += ($v - $mean) * ($v - $mean);
            }
            $std = sqrt($vars / count($velocities));
            $f['velMean'] = $mean;
            $f['velCv'] = $mean > 0 ? ($std / $mean) : 0.0;
        }
        return $f;
    }

    /** 兼容旧版客户端（仅上报聚合值 t/mv/ent/touch）：降级为保守特征 */
    private static function featuresFromLegacy(array $d)
    {
        $t  = isset($d['t'])  ? (int)$d['t']  : 0;
        $mv = isset($d['mv']) ? (int)$d['mv'] : 0;
        $ent = isset($d['ent']) ? ((int)$d['ent'] / 100.0) : 0;
        return [
            'points'   => $mv,
            'duration' => $t,
            'pathLength' => 0,
            'straight' => 0,
            'jitter'   => null,
            'ent'      => $ent,
            'dirChanges' => (int)($mv * 0.4),
            'velMean'  => null,
            'velCv'    => null,
            'valid'    => $mv >= 2,
        ];
    }

    // ============================================================
    //  二、单信号风险函数（返回 0-1，1=最高风险）
    // ============================================================

    private static function clamp01($v)
    {
        return max(0.0, min(1.0, (float)$v));
    }

    private static function sigTooFast($f)
    {
        $dur = (float)$f['duration'];
        if ($dur <= 0) {
            return 1.0;
        }
        if ($dur >= self::FAST_MS) {
            return 0.0;
        }
        return self::clamp01((self::FAST_MS - $dur) / self::FAST_MS);
    }

    private static function sigTooSparse($f)
    {
        $n = (int)$f['points'];
        if ($n >= self::MIN_MOVES) {
            return 0.0;
        }
        return self::clamp01((self::MIN_MOVES - $n) / self::MIN_MOVES);
    }

    private static function sigLowEntropy($f)
    {
        $ent = (float)$f['ent'];
        return self::clamp01(1 - $ent);
    }

    private static function sigTooStraight($f)
    {
        if ($f['jitter'] === null) {
            return null;
        }
        $j = (float)$f['jitter'];
        if ($j >= self::STRAIGHT_MAX) {
            return 0.0;
        }
        return self::clamp01((self::STRAIGHT_MAX - $j) / self::STRAIGHT_MAX);
    }

    private static function sigTooNoisy($f)
    {
        if ($f['jitter'] === null) {
            return null;
        }
        $j = (float)$f['jitter'];
        if ($j <= self::NOISY_MIN) {
            return 0.0;
        }
        return self::clamp01(($j - self::NOISY_MIN) / (1 - self::NOISY_MIN));
    }

    private static function sigLowDirChange($f)
    {
        $n = max(1, (int)$f['points']);
        $ratio = (int)$f['dirChanges'] / $n;
        if ($ratio >= self::DC_RATIO_MIN) {
            return 0.0;
        }
        return self::clamp01((self::DC_RATIO_MIN - $ratio) / self::DC_RATIO_MIN);
    }

    private static function sigConstantVel($f)
    {
        if ($f['velCv'] === null) {
            return null;
        }
        $cv = (float)$f['velCv'];
        if ($cv >= self::VEL_CV_MIN) {
            return 0.0;
        }
        return self::clamp01((self::VEL_CV_MIN - $cv) / self::VEL_CV_MIN);
    }

    private static function sigEnvMissing($env)
    {
        $risk = 0.0;
        // 缺失硬件并发数或设备内存，常见于 headless / 隐私模式 / 自动化框架
        if (empty($env['cores'])) {
            $risk += 0.5;
        }
        if (!isset($env['mem']) || $env['mem'] === '' || $env['mem'] === null) {
            $risk += 0.5;
        }
        return self::clamp01($risk);
    }

    private static function sigTzMismatch($env, $serverTzMin)
    {
        if (!isset($env['tz']) || $env['tz'] === '' || $env['tz'] === null) {
            return 0.3; // 未知，轻度风险
        }
        $client = (int)$env['tz'];
        if (abs($client - (int)$serverTzMin) > 60) {
            return 1.0;
        }
        return 0.0;
    }

    private static function sigTokenAge($ageMs)
    {
        $age = (int)$ageMs;
        if ($age <= self::TOKEN_AGE_MIN) {
            return 1.0;
        }
        if ($age >= 600) {
            return 0.0;
        }
        return self::clamp01((self::TOKEN_AGE_MIN - $age) / self::TOKEN_AGE_MIN);
    }

    private static function sigIpFreq($count)
    {
        $c = (int)$count;
        if ($c <= self::IP_FREQ_MAX) {
            return 0.0;
        }
        return self::clamp01(($c - self::IP_FREQ_MAX) / self::IP_FREQ_MAX);
    }

    // ============================================================
    //  三、评分与分级决策
    // ============================================================

    /**
     * 计算 0-100 风险分。
     * @param array $f    行为特征（extractFromTrajectory 或 featuresFromLegacy 结果）
     * @param array $env  客户端环境指纹
     * @param array $ctx  服务端上下文：['tokenAge'=>ms,'ipFreq'=>int,'serverTz'=>min]
     */
    public static function score(array $f, array $env, array $ctx)
    {
        $serverTz = isset($ctx['serverTz']) ? (int)$ctx['serverTz'] : (int)(date('Z') / 60);

        $behavior = [
            'tooFast'      => self::sigTooFast($f),
            'tooSparse'    => self::sigTooSparse($f),
            'lowEntropy'   => self::sigLowEntropy($f),
            'tooStraight'  => self::sigTooStraight($f),
            'tooNoisy'     => self::sigTooNoisy($f),
            'lowDirChange' => self::sigLowDirChange($f),
            'constantVel'  => self::sigConstantVel($f),
        ];
        $envSignals = [
            'envMissing'   => self::sigEnvMissing($env),
            'tzMismatch'   => self::sigTzMismatch($env, $serverTz),
        ];
        $serverSignals = [
            'tokenAge'     => self::sigTokenAge(isset($ctx['tokenAge']) ? $ctx['tokenAge'] : 0),
            'ipFreq'       => self::sigIpFreq(isset($ctx['ipFreq']) ? $ctx['ipFreq'] : 0),
        ];

        $bs = self::avg($behavior);
        $es = self::avg($envSignals);
        $ss = self::avg($serverSignals);

        $wb = (float)self::cfg('passive_w_behavior', 60) / 100.0;
        $we = (float)self::cfg('passive_w_env', 20) / 100.0;
        $ws = (float)self::cfg('passive_w_server', 20) / 100.0;
        $total = $wb + $we + $ws;
        if ($total <= 0) {
            return 0;
        }
        $score = ($wb * $bs + $we * $es + $ws * $ss) / $total * 100.0;
        return (int)round(self::clamp01($score / 100.0) * 100.0);
    }

    /** 组内信号均值（忽略 null/未知信号） */
    private static function avg(array $signals)
    {
        $sum = 0.0; $cnt = 0;
        foreach ($signals as $v) {
            if ($v === null) {
                continue;
            }
            $sum += self::clamp01($v);
            $cnt++;
        }
        return $cnt ? ($sum / $cnt) : 0.0;
    }

    /** 依据强度档位 / 自定义阈值返回分级阈值 */
    public static function thresholds()
    {
        $intensity = self::cfg('passive_verify_intensity', 'normal');
        if ($intensity === 'custom') {
            return [
                (int)self::cfg('passive_score_pass', 35),
                (int)self::cfg('passive_score_challenge', 70),
                (int)self::cfg('passive_score_block', 88),
            ];
        }
        return self::INTENSITY[$intensity] ?? self::INTENSITY['normal'];
    }

    /**
     * 分级决策。
     * @return string 'pass' | 'challenge' | 'block'
     */
    public static function decide($score, $ip = null)
    {
        if ($ip !== null && self::allowlisted($ip)) {
            return 'pass';
        }
        list($pass, $challenge, $block) = self::thresholds();
        if ($score <= $pass) {
            return 'pass';
        }
        if ($score <= $challenge) {
            return 'challenge';
        }
        return 'block';
    }

    /**
     * 统一入口：解析答案 JSON + 上下文 → 返回决策。
     * @param string $answerJson  客户端上报（含 traj+env 或旧版 t/mv/ent/touch）
     * @param array  $ctx         服务端上下文 ['ip','tokenAge','ipFreq','serverTz']
     * @return array ['ok'=>bool,'decision'=>str,'score'=>int,'legacy'=>bool,'reason'=>str]
     */
    public static function evaluate($answerJson, array $ctx)
    {
        $d = json_decode($answerJson, true);
        if (!is_array($d)) {
            return self::deny('malformed');
        }
        $legacy = !isset($d['traj']) || !is_array($d['traj']);
        $f = $legacy ? self::featuresFromLegacy($d) : self::extractFromTrajectory($d['traj']);
        $env = isset($d['env']) && is_array($d['env']) ? $d['env'] : [];

        // 采集窗口内无足够真实交互（如静止未动）：先降级为交互式验证，避免误杀正常用户
        if (!$f['valid']) {
            return ['ok' => false, 'decision' => 'challenge', 'score' => 100, 'legacy' => $legacy, 'reason' => 'insufficient'];
        }
        // 反伪造：新格式必须特征自洽（时长合理、抖动/熵在 [0,1]）
        if (!$legacy && !self::validateFeatures($f)) {
            return self::deny('invalid_features');
        }

        // 触发条件：仅对可疑流量评分（更低摩擦）—— 基线干净时直接放行
        if (self::cfg('passive_trigger', 'always') === 'suspicious' && self::baselineClean($env, $ctx)) {
            return ['ok' => true, 'decision' => 'pass', 'score' => 0, 'legacy' => $legacy, 'reason' => 'suspicious_bypass'];
        }

        $score = self::score($f, $env, $ctx);
        $decision = self::decide($score, $ctx['ip'] ?? null);
        return [
            'ok'       => $decision === 'pass',
            'decision' => $decision,
            'score'    => $score,
            'legacy'   => $legacy,
            'reason'   => $legacy ? 'legacy' : 'trajectory',
        ];
    }

    private static function deny($reason)
    {
        return ['ok' => false, 'decision' => 'block', 'score' => 100, 'legacy' => false, 'reason' => $reason];
    }

    /**
     * 基线是否「干净」：用于 trigger=suspicious 模式下的快速放行判定。
     * 仅当环境指纹正常、时区匹配、且服务端信号（IP 频率 / 令牌时效）无异常时才放行。
     */
    private static function baselineClean(array $env, array $ctx)
    {
        if (self::sigEnvMissing($env) > 0) {
            return false; // 缺失设备指纹（headless / 隐私模式）不视为干净
        }
        $serverTz = isset($ctx['serverTz']) ? (int)$ctx['serverTz'] : (int)(date('Z') / 60);
        if (self::sigTzMismatch($env, $serverTz) > 0) {
            return false;
        }
        if (self::sigIpFreq(isset($ctx['ipFreq']) ? $ctx['ipFreq'] : 0) > 0) {
            return false; // 同 IP 短时间内大量申请验证码 → 可疑
        }
        if (self::sigTokenAge(isset($ctx['tokenAge']) ? $ctx['tokenAge'] : 0) > 0) {
            return false; // 令牌刚下发即提交 → 可疑
        }
        return true;
    }

    /** 反伪造：特征自洽性校验 */
    public static function validateFeatures(array $f)
    {
        if (empty($f['valid'])) {
            return false;
        }
        if ($f['duration'] < 50) {
            return false; // 50ms 内完成 2+ 点采集不可能
        }
        if ($f['jitter'] !== null && ($f['jitter'] < -0.01 || $f['jitter'] > 1.01)) {
            return false;
        }
        if ($f['ent'] < -0.01 || $f['ent'] > 1.01) {
            return false;
        }
        return true;
    }

    // ============================================================
    //  四、放行策略：IP 白名单 + 信任凭证
    // ============================================================

    /** IP/CIDR 白名单匹配（恒放行）。支持精确 IP / 通配 192.168.1.* / CIDR 10.0.0.0/8 */
    public static function allowlisted($ip)
    {
        $raw = trim((string)self::cfg('passive_allowlist', ''));
        if ($raw === '') {
            return false;
        }
        $ip = (string)$ip;
        foreach (preg_split('/\s+/', $raw) as $rule) {
            $rule = trim($rule);
            if ($rule === '') {
                continue;
            }
            if (self::ipMatch($ip, $rule)) {
                return true;
            }
        }
        return false;
    }

    private static function ipMatch($ip, $rule)
    {
        if ($rule === $ip) {
            return true;
        }
        if (strpos($rule, '*') !== false) {
            $pat = '/^' . str_replace('\*', '.*', preg_quote($rule, '/')) . '$/';
            return (bool)preg_match($pat, $ip);
        }
        if (strpos($rule, '/') !== false) {
            list($subnet, $mask) = explode('/', $rule, 2);
            $ipLong = ip2long($ip);
            $subLong = ip2long($subnet);
            if ($ipLong === false || $subLong === false) {
                return false;
            }
            $maskLong = ~((1 << (32 - (int)$mask)) - 1);
            return ($ipLong & $maskLong) === ($subLong & $maskLong);
        }
        return false;
    }

    /** 签发信任凭证（HMAC 签名，绑定 IP 与过期时间） */
    public static function issueTrust($ip, $ttl)
    {
        $exp = time() + (int)$ttl;
        $nonce = rand_str(16);
        $payload = $ip . '|' . $exp . '|' . $nonce;
        $sig = hash_hmac('sha256', $payload, self::secret());
        return base64_encode($payload . '|' . $sig);
    }

    /** 同 IP 在统计窗口内下发的验证码数量（服务端反爬信号） */
    public static function ipFreq($ip)
    {
        try {
            $n = Database::getInstance()->one(
                "SELECT COUNT(*) AS c FROM captcha_tokens WHERE ip=? AND created_at>?",
                [(string)$ip, time() - self::IP_FREQ_WINDOW]
            );
            return (int)($n['c'] ?? 0);
        } catch (Exception $e) {
            return 0;
        }
    }

    /** 校验信任凭证 */
    public static function verifyTrust($token, $ip)
    {
        if (!is_string($token) || $token === '') {
            return false;
        }
        $dec = base64_decode($token, true);
        if ($dec === false) {
            return false;
        }
        $parts = explode('|', $dec);
        if (count($parts) !== 4) {
            return false;
        }
        list($pIp, $exp, $nonce, $sig) = $parts;
        if ((int)$exp < time()) {
            return false;
        }
        if ($pIp !== (string)$ip) {
            return false; // 绑定 IP，防止凭证跨网络复用
        }
        $expect = hash_hmac('sha256', $pIp . '|' . $exp . '|' . $nonce, self::secret());
        return hash_equals($expect, (string)$sig);
    }

    /** 无感验证通过时下发信任 Cookie（HttpOnly，前端无需读取） */
    public static function issueTrustCookie($ip)
    {
        $ttl = (int)self::cfg('passive_trust_ttl', 3600);
        $tok = self::issueTrust($ip, $ttl);
        $base = Config::get('site_url', '');
        $path = '/';
        if (function_exists('setcookie')) {
            setcookie(self::TRUST_COOKIE, $tok, [
                'expires'  => time() + $ttl,
                'path'     => $path,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        return $tok;
    }

    /** 当前请求是否持有有效信任凭证（跨页无感放行） */
    public static function trustValid()
    {
        $c = $_COOKIE[self::TRUST_COOKIE] ?? '';
        if (!$c) {
            return false;
        }
        return self::verifyTrust($c, Request::ip());
    }

    public static function clearTrust()
    {
        if (function_exists('setcookie')) {
            setcookie(self::TRUST_COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
        }
    }
}
