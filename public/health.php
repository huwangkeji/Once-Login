<?php
/**
 * 健康检查端点（运维探活 / 负载均衡探测）
 *
 * 用法：GET /health.php
 * 返回：JSON（200 = 全部正常；503 = degraded）
 *   {
 *     "status": "ok|degraded",
 *     "service": "uic-auth-center",
 *     "version": "1.0.0",
 *     "time": "...",
 *     "checks": { "database": "ok", "ucenter": "ok" }
 *   }
 *
 * 安全：无敏感信息、无登录要求、禁止缓存；仅做轻量可达性探测。
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$dbOk = true;
try {
    Database::getInstance()->val('SELECT 1');
} catch (Exception $e) {
    $dbOk = false;
}

$ucOk = false;
try {
    $ping = (new UCenterClient())->ping();
    $ucOk = !empty($ping['ok']);
} catch (Exception $e) {
    $ucOk = false;
}

// UCenter 驱动探测：mock 是本地模拟驱动，一旦出现在生产即为严重事故，
// 此处直接判定为 degraded，让负载均衡/监控在发布后立刻发现。
$driver   = (string)Config::get('uc_driver', 'http');
$isMock   = ($driver === 'mock');

$warnings = [];
if ($isMock) {
    $warnings[] = 'uc_driver=mock：正在使用本地模拟 UCenter，注册/登录不会写入真实 UCenter，严禁用于生产';
}
if (!$dbOk) {
    $warnings[] = 'database 不可达';
}
if (!$ucOk) {
    $warnings[] = 'ucenter 不可达或密钥/AppID 不匹配';
}

$ok = $dbOk && $ucOk && !$isMock;
http_response_code($ok ? 200 : 503);
echo json_encode([
    'status'   => $ok ? 'ok' : 'degraded',
    'service'  => 'uic-auth-center',
    'version'  => UIC_VERSION,
    'time'     => date('c'),
    'checks'   => [
        'database'  => $dbOk ? 'ok' : 'error',
        'ucenter'   => $ucOk ? 'ok' : 'error',
        'uc_driver' => $isMock ? 'mock(unsafe-for-prod)' : $driver,
    ],
    'warnings' => $warnings,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);