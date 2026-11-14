<?php
/**
 * =============================================================
 *  UCenter 协议兼容服务端（本地联调用）
 * -------------------------------------------------------------
 *  用途：在没有真实 Discuz! UCenter 的环境里，验证 UIC 的 http
 *        驱动能否走通「真实 HTTP + authcode + XML」完整链路。
 *
 *  它按 UCenter 1.5/1.6 标准协议工作：
 *    请求：POST index.php  m=模块&a=动作&inajax=2&input=<authcode加密串>&appid=xxx
 *          input 解密后为 query string（业务参数 + agent + time）
 *    响应：把处理结果 xml_serialize 后再用同一密钥 authcode 加密回传
 *
 *  账号逻辑复用 MockDriver（同一份 uc_members 表），因此切换驱动后
 *  上层业务行为完全一致，只是把「进程内调用」换成「真实 HTTP 调用」。
 *
 *  启动：php -S 127.0.0.5:8081 -t tools/ucserver
 *  然后 UIC 后台把 uc_driver 设为 http、uc_url 设为 http://127.0.0.5:8081
 * =============================================================
 */
define('UC_ROOT', dirname(__DIR__, 2));   // 项目根目录

// ---- 加载协议与账号逻辑（与 UIC 同一套实现，保证协议一致）----
require_once UC_ROOT . '/app/Core/Authcode.php';
require_once UC_ROOT . '/app/Core/XmlKit.php';
require_once UC_ROOT . '/app/Core/Validator.php';
require_once UC_ROOT . '/app/Core/Request.php';
require_once UC_ROOT . '/app/Core/UCenter/UCenterDriver.php';
require_once UC_ROOT . '/app/Core/UCenter/MockDriver.php';

// ---- 通信密钥：优先读 settings 表（安装时随机生成），连不上库时退回 config 默认 ----
$cfg = require UC_ROOT . '/config/config.php';
$KEY = $cfg['ucenter']['key'] ?? '';
if ($KEY === '') {
    try {
        require_once UC_ROOT . '/app/bootstrap.php';
        $KEY = trim((string)Config::get('uc_key', ''), '"');
    } catch (Throwable $e) {
        $KEY = '';
    }
}

$LOG = UC_ROOT . '/data/ucserver_access.log';

/** 记录本次通信，便于联调时核对 */
function uclog($line)
{
    global $LOG;
    @file_put_contents($LOG, date('H:i:s') . ' ' . $line . "\n", FILE_APPEND);
}

// ===================== 协议入口 =====================
$m     = strtolower($_POST['m'] ?? $_GET['m'] ?? '');
$a     = strtolower($_POST['a'] ?? $_GET['a'] ?? '');
$input = $_POST['input'] ?? $_GET['input'] ?? '';
$appid = $_POST['appid'] ?? $_GET['appid'] ?? '';

// 无 input：返回一个状态页（方便确认服务端是否活着）
if ($input === '') {
    header('Content-Type: text/plain; charset=utf-8');
    echo "UCenter 协议兼容服务端 运行中\n";
    echo "key=" . $KEY . "\n";
    echo "usage: POST m=user&a=checkname&input=<authcode(...args...&agent=..&time=..)>&appid=..\n";
    uclog("STATUS page");
    exit;
}

// ---------- 1. 解密 input ----------
$plain = Authcode::decode($input, $KEY);
if ($plain === null || $plain === '') {
    uclog("DECRYPT FAIL from appid=$appid");
    http_response_code(403);
    echo Authcode::encode('-1', $KEY);   // 解密失败：协议层拒绝
    exit;
}

// ---------- 2. 解析业务参数 ----------
$args = [];
parse_str($plain, $args);
unset($args['agent'], $args['time']);    // 协议附加字段，不参与业务

// ---------- 3. 分派 ----------
try {
    $driver = new MockDriver(['key' => $KEY, 'appid' => $appid]);
    $result = $driver->call($m, $a, $args);
} catch (Throwable $e) {
    $result = -7;
    uclog("EXCEPTION " . $e->getMessage());
}

// ---------- 4. 响应序列化（数组走 XML，标量直接回）----------
if (is_array($result)) {
    $payload = XmlKit::serialize($result);
} else {
    $payload = (string)$result;
}

uclog(sprintf(
    'appid=%s %s.%s args=%s => %s',
    $appid, $m, $a,
    json_encode($args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    is_array($result) ? json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $result
));

// ---------- 5. 加密回传 ----------
echo Authcode::encode($payload, $KEY);
