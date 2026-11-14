<?php
/**
 * 无感风险引擎（RiskEngine）单元测试
 * 命令行：php tools/passive_risk_test.php
 * 不依赖 Web，直接校验评分/分级/白名单/信任凭证等核心逻辑。
 * 注意：本测试会在运行时临时改写 passive_allowlist，结束前恢复原值，不影响线上配置。
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

$pass = 0;
$fail = 0;
function check($name, $cond, $detail = '')
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  [PASS] $name\n";
    } else {
        $fail++;
        echo "  [FAIL] $name  $detail\n";
    }
}

$serverTz = (int)(date('Z') / 60);

// 临时清空白名单，避免历史测试残留污染；结束前恢复原值
$origAllow = Config::get('passive_allowlist', '');
Config::set('passive_allowlist', '');

// ---------- 构造样本轨迹 ----------
function humanTraj($n = 100, $dur = 1500)
{
    $traj = [];
    for ($i = 0; $i < $n; $i++) {
        $t = round($i / $n * $dur);
        $x = 20 + 300 * ($i / $n) + 30 * sin($i / 5);
        $y = 40 + 80 * cos($i / 7) + 20 * sin($i / 3);
        $traj[] = [$t, round($x), round($y)];
    }
    return $traj;
}
function botStraightTraj($n = 12, $dur = 120)
{
    $traj = [];
    for ($i = 0; $i < $n; $i++) {
        $t = round($i / $n * $dur);
        $traj[] = [$t, round(20 + $i * 25), 50];
    }
    return $traj;
}
function envNormal()
{
    global $serverTz;
    return ['cores' => 8, 'mem' => 8, 'tz' => -$serverTz, 'lang' => 'zh-CN', 'pxr' => 2, 'dpr' => 2, 'touch' => 0];
}
function envHeadless()
{
    return []; // 缺失 cores / mem → 自动化特征
}
function envExtreme()
{
    global $serverTz;
    // 缺失设备指纹 + 时区不符（双重自动化信号）
    return ['tz' => -($serverTz + 120), 'lang' => '', 'pxr' => 1, 'dpr' => 1, 'touch' => 0];
}
function ctx($ip = '203.0.113.5', $age = 1500, $freq = 0)
{
    global $serverTz;
    return ['ip' => $ip, 'tokenAge' => $age, 'ipFreq' => $freq, 'serverTz' => $serverTz];
}

echo "== 1. 特征提取 ==\n";
$f = RiskEngine::extractFromTrajectory(humanTraj());
check('人类轨迹：点数=100', $f['points'] === 100, 'got ' . $f['points']);
check('人类轨迹：时长≈1500', abs($f['duration'] - 1500) < 50, 'got ' . $f['duration']);
check('人类轨迹：抖动在 (0,1)', $f['jitter'] > 0.05 && $f['jitter'] < 1, 'jitter=' . var_export($f['jitter'], true));
check('人类轨迹：方向熵 > 0.3', $f['ent'] > 0.3, 'ent=' . $f['ent']);
check('人类轨迹：valid=true', $f['valid'] === true);
$f2 = RiskEngine::extractFromTrajectory(botStraightTraj());
check('机器人直线：抖动≈0', $f2['jitter'] !== null && $f2['jitter'] < 0.02, 'jitter=' . var_export($f2['jitter'], true));
check('机器人直线：方向熵低 <0.2', $f2['ent'] < 0.2, 'ent=' . $f2['ent']);

echo "\n== 2. 评分与分级（默认 normal 档位）==\n";
$ctxC = ctx();
$rHuman = RiskEngine::evaluate(json_encode(['traj' => humanTraj(), 'env' => envNormal()]), $ctxC);
check('人类：判定 pass', $rHuman['decision'] === 'pass', 'score=' . $rHuman['score'] . ' decision=' . $rHuman['decision']);
check('人类：分数较低 ≤35', $rHuman['score'] <= 35, 'score=' . $rHuman['score']);

$rBot = RiskEngine::evaluate(json_encode(['traj' => botStraightTraj(), 'env' => envHeadless()]), $ctxC);
check('机器人(headless+直线)：不放行', in_array($rBot['decision'], ['challenge', 'block'], true), 'score=' . $rBot['score'] . ' decision=' . $rBot['decision']);

$rMid = RiskEngine::evaluate(json_encode(['traj' => botStraightTraj(), 'env' => envNormal()]), $ctxC);
check('中等可疑(直线但环境正常)：challenge 或 block', in_array($rMid['decision'], ['challenge', 'block'], true), 'score=' . $rMid['score'] . ' decision=' . $rMid['decision']);

echo "\n== 2b. 极端高风险组合 → 直接拦截(block) ==\n";
$rExtreme = RiskEngine::evaluate(
    json_encode(['traj' => [[0, 10, 10], [100, 260, 10]], 'env' => envExtreme()]),
    ctx('203.0.113.5', 50, 20)
);
check('极端高风险：判定 block', $rExtreme['decision'] === 'block', 'score=' . $rExtreme['score'] . ' decision=' . $rExtreme['decision']);

echo "\n== 2c. 恶意/伪造载荷 → 直接拦截(block) ==\n";
$rMalformed = RiskEngine::evaluate('not-json', $ctxC);
check('非 JSON 载荷：判定 block', $rMalformed['decision'] === 'block', 'decision=' . $rMalformed['decision']);

echo "\n== 3. 静止用户降级为 challenge（不误杀）==\n";
$rStill = RiskEngine::evaluate(json_encode(['traj' => [[0, 10, 10]], 'env' => envNormal()]), $ctxC);
check('静止(1点)：decision=challenge', $rStill['decision'] === 'challenge', 'decision=' . $rStill['decision']);

echo "\n== 4. 反伪造：特征越界校验被拒 ==\n";
$fBad = ['valid' => true, 'duration' => 100, 'jitter' => 5.0, 'ent' => 0.5, 'points' => 10, 'pathLength' => 0, 'straight' => 0, 'dirChanges' => 0, 'velMean' => null, 'velCv' => null];
check('越界 jitter 校验失败', RiskEngine::validateFeatures($fBad) === false);
$fBad2 = ['valid' => true, 'duration' => 20, 'jitter' => 0.3, 'ent' => 0.5, 'points' => 10, 'pathLength' => 0, 'straight' => 0, 'dirChanges' => 0, 'velMean' => null, 'velCv' => null];
check('时长<50ms 校验失败', RiskEngine::validateFeatures($fBad2) === false);

echo "\n== 5. 信任凭证（HMAC 签名 + IP 绑定）==\n";
$tok = RiskEngine::issueTrust('203.0.113.5', 3600);
check('同 IP 校验通过', RiskEngine::verifyTrust($tok, '203.0.113.5') === true);
check('异 IP 校验拒绝', RiskEngine::verifyTrust($tok, '198.51.100.9') === false);
$tokBad = base64_encode('203.0.113.5|' . (time() + 3600) . '|abc|forged');
check('伪造签名校验拒绝', RiskEngine::verifyTrust($tokBad, '203.0.113.5') === false);
$expired = RiskEngine::issueTrust('203.0.113.5', -10);
check('过期凭证校验拒绝', RiskEngine::verifyTrust($expired, '203.0.113.5') === false);

echo "\n== 6. 触发条件 suspicious：基线干净时放行 ==\n";
$rSus = RiskEngine::evaluate(json_encode(['traj' => [], 'env' => envNormal()]), $ctxC);
check('suspicious 模式入口结构正确', isset($rSus['decision']), 'decision=' . ($rSus['decision'] ?? 'n/a'));

echo "\n== 7. 旧版客户端兼容（聚合字段 t/mv/ent）==\n";
$rLegacy = RiskEngine::evaluate(json_encode(['t' => 1500, 'mv' => 40, 'ent' => 70, 'touch' => 0]), $ctxC);
check('旧版字段：可解析且给出决策', isset($rLegacy['decision']) && $rLegacy['legacy'] === true, 'decision=' . ($rLegacy['decision'] ?? 'n/a'));

echo "\n== 8. 放行策略：IP/CIDR 白名单 ==\n";
Config::set('passive_allowlist', "198.51.100.9\n10.0.0.0/8");
check('精确 IP 命中白名单', RiskEngine::allowlisted('198.51.100.9') === true);
check('CIDR 命中白名单', RiskEngine::allowlisted('10.5.6.7') === true);
check('非白名单 IP 不命中', RiskEngine::allowlisted('8.8.8.8') === false);
check('测试 IP 不在白名单', RiskEngine::allowlisted('203.0.113.5') === false);
$rAllow = RiskEngine::evaluate(json_encode(['traj' => botStraightTraj(), 'env' => envHeadless()]), ctx('198.51.100.9'));
check('白名单 IP：即便像机器人也判定 pass', $rAllow['decision'] === 'pass', 'decision=' . $rAllow['decision']);
Config::set('passive_allowlist', ''); // 恢复干净

// 恢复原始白名单配置
Config::set('passive_allowlist', $origAllow);

echo "\n========================================\n";
echo "结果：PASS=$pass  FAIL=$fail\n";
exit($fail > 0 ? 1 : 0);
