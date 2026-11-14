<?php
/**
 * =============================================================
 *  UCenter 通知接收端 自测脚本
 *
 *  用法：
 *    php tools/ucnotify_selftest.php                     # 进程内直调（验证业务逻辑）
 *    php tools/ucnotify_selftest.php --http=http://host  # 走真实 HTTP 回环（验证协议层）
 *
 *  覆盖：UCenter 后台「通知设置」全部开关 + 协议边界（密钥错/时间戳过期/缺参/明文兜底）
 * =============================================================
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

$mode    = 'inner';
$baseUrl = '';
foreach ($argv as $a) {
    if (strpos($a, '--http=') === 0) {
        $mode    = 'http';
        $baseUrl = substr($a, 7);
    }
}

$KEY = (string)Config::get('uc_key', '');
$PASS = 0;
$FAIL = 0;
$FAILED = [];

/** 打印一条结果 */
function check($label, $actual, $expect, $strict = true)
{
    global $PASS, $FAIL, $FAILED;
    $ok = $strict
        ? ($actual === $expect)
        : (strpos((string)$actual, (string)$expect) !== false);
    if ($ok) {
        $PASS++;
        printf("  [OK]   %-46s => %s\n", $label, mb_substr((string)$actual, 0, 60));
    } else {
        $FAIL++;
        $FAILED[] = $label;
        printf("  [FAIL] %-46s => %s  (期望 %s)\n", $label, mb_substr((string)$actual, 0, 60), $expect);
    }
}

/** 用与 UCenter 完全相同的方式构造密文 */
function makeCode(array $data)
{
    global $KEY;
    return Authcode::encode(http_build_query($data), $KEY);
}

/** 发送一条通知（进程内 / HTTP） */
function send($code, $mode, $baseUrl)
{
    if ($mode === 'inner') {
        $r = NotifyHandler::receive($code);
        return $r['body'];
    }
    $url = rtrim($baseUrl, '/') . '/api/uc.php?code=' . urlencode($code);
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_USERAGENT      => 'UCenter/1.6 Test',
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    return is_string($body) ? trim($body) : '(请求失败)';
}

/**
 * 把密文「裸拼」进 URL（不做 urlencode）
 * 用于复现 UCenter 未编码时的经典陷阱：密文中的 '+' 被 PHP 解析成空格
 */
function sendRawCode($cipher, $mode, $baseUrl)
{
    if ($mode === 'inner') {
        return '(进程内模式跳过)';
    }
    $url = rtrim($baseUrl, '/') . '/api/uc.php?code=' . $cipher;
    $ch  = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
    $body = curl_exec($ch);
    curl_close($ch);
    return is_string($body) ? trim($body) : '(请求失败)';
}

/** 直接对 URL 发请求（模拟 UCenter 直接拼参的形态） */
function sendRawUrl($query, $mode, $baseUrl)
{
    if ($mode === 'inner') {
        return '(进程内模式跳过)';
    }
    $url = rtrim($baseUrl, '/') . '/api/uc.php?' . $query;
    $ch  = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
    $body = curl_exec($ch);
    curl_close($ch);
    return is_string($body) ? trim($body) : '(请求失败)';
}

echo "=====================================================\n";
echo " UCenter 通知接收端 自测\n";
echo " 模式：" . ($mode === 'http' ? "HTTP 回环 $baseUrl" : '进程内直调') . "\n";
echo " 密钥：" . (substr($KEY, 0, 3) . '****' . substr($KEY, -3)) . "\n";
echo " 时间：" . date('Y-m-d H:i:s') . "\n";
echo "=====================================================\n\n";

NotifyHandler::ensureTable();

$db  = Database::getInstance();
$now = time();
$T0  = $now;   // 用于结束时清理本次自测产生的日志/记录

// ===========================================================
//  一、开关类动作（对照 UCenter 后台「通知设置」每一项）
// ===========================================================
echo "[1] UCenter 后台「通知设置」各开关对应动作\n";

$oneShot = [
    'test'                 => '（应用管理→测试通知）',
    'updatebadwords'       => '更新关键字列表通知',
    'updatehosts'          => '更新域名解析缓存通知',
    'updateapps'           => '更新应用列表通知',
    'updateclient'         => '更新客户端缓存通知',
    'updatecache'          => '更新缓存',
    'updatecreditsettings' => '更新应用积分设置通知',
    'addfeed'              => '添加事件通知',
    'synlogin'             => '同步登录通知',
    'synlogout'            => '同步退出通知',
];
foreach ($oneShot as $act => $label) {
    $data = ['action' => $act, 'time' => $now, 'appid' => Config::get('uc_appid', '')];
    if ($act === 'addfeed') {
        $data += ['uid' => 999001, 'title' => '自测事件', 'body' => '这是一条自测动态'];
    }
    if ($act === 'synlogin' || $act === 'synlogout') {
        $data += ['uid' => 999001];
    }
    check($act, send(makeCode($data), $mode, $baseUrl), '1');
}

// 带数据的返回型动作（先清历史积分，保证可重复运行）
Config::remove('uc_credits');
check('getcredit（应返回数字）', send(makeCode([
    'action' => 'getcredit', 'time' => $now, 'uid' => 999001, 'credit' => 1,
]), $mode, $baseUrl), '0');

$rcs = send(makeCode(['action' => 'getcreditsettings', 'time' => $now]), $mode, $baseUrl);
check('getcreditsettings（应返回 serialize）', $rcs, 'a:0:{}');

$rtag = send(makeCode(['action' => 'gettagg', 'time' => $now]), $mode, $baseUrl);
check('gettagg（应返回 serialize）', $rtag, 'a:0:{}');

// 更新用户积分
check('updatecredit', send(makeCode([
    'action' => 'updatecredit', 'time' => $now, 'uid' => 999001, 'credit' => serialize([1 => 100]),
]), $mode, $baseUrl), '1');

// ===========================================================
//  二、真实业务落地：改名 / 改密踢下线 / 删除用户
// ===========================================================
echo "\n[2] 通知业务真实落地（临时用户 uid=999001）\n";

$TUID = 999001;
$db->exec("DELETE FROM user_profiles WHERE uid=?", [$TUID]);
$db->exec("DELETE FROM sessions WHERE uid=?", [$TUID]);
$db->exec("DELETE FROM tokens WHERE uid=?", [$TUID]);
$db->insert('user_profiles', [
    'uid' => $TUID, 'username' => 'selftest_u', 'nickname' => '自测',
    'sync_status' => 1, 'created_at' => $now, 'updated_at' => $now,
]);
$db->insert('sessions', [
    'sid' => 'selftest_sid_' . mt_rand(1000, 9999), 'uid' => $TUID, 'username' => 'selftest_u',
    'source' => 'center', 'login_at' => $now, 'last_at' => $now,
    'expires_at' => $now + 7200, 'revoked' => 0,
]);

// --- 改名 ---
check('renameuser 回执', send(makeCode([
    'action' => 'renameuser', 'time' => $now, 'uid' => $TUID,
    'oldusername' => 'selftest_u', 'newusername' => 'selftest_renamed',
]), $mode, $baseUrl), '1');
$row = $db->one("SELECT username FROM user_profiles WHERE uid=?", [$TUID]);
check('renameuser 已改本地用户名', $row ? $row['username'] : '(无记录)', 'selftest_renamed');
$srow = $db->one("SELECT username FROM sessions WHERE uid=?", [$TUID]);
check('renameuser 已同步会话用户名', $srow ? $srow['username'] : '(无会话)', 'selftest_renamed');

// --- 改密（应踢下线）---
$db->exec("UPDATE sessions SET revoked=0 WHERE uid=?", [$TUID]);
check('updatepw 回执', send(makeCode([
    'action' => 'updatepw', 'time' => $now, 'uid' => $TUID, 'username' => 'selftest_renamed',
]), $mode, $baseUrl), '1');
$rev = intval($db->val("SELECT COUNT(*) FROM sessions WHERE uid=? AND revoked=1", [$TUID]));
check('updatepw 已强制下线会话', $rev > 0 ? '1' : '0', '1', true);

// --- 删除用户 ---
check('deleteuser 回执', send(makeCode([
    'action' => 'deleteuser', 'time' => $now, 'uid' => $TUID, 'username' => 'selftest_renamed',
]), $mode, $baseUrl), '1');
$left = intval($db->val("SELECT COUNT(*) FROM user_profiles WHERE uid=?", [$TUID]));
check('deleteuser 已清理本地资料', (string)$left, '0');

// 收尾
$db->exec("DELETE FROM sessions WHERE uid=?", [$TUID]);

// ===========================================================
//  三、协议边界与容错
// ===========================================================
echo "\n[3] 协议边界与容错\n";

// 错误密钥
$badCode = Authcode::encode(http_build_query(['action' => 'test', 'time' => $now]), 'WRONG-KEY-123456');
$rb = send($badCode, $mode, $baseUrl);
check('错误密钥应被拒绝', $rb, 'Invalid Request', false);

// 时间戳过期
$expired = makeCode(['action' => 'test', 'time' => $now - 7200]);
$re = send($expired, $mode, $baseUrl);
check('过期时间戳应被拒绝', $re, 'expiried', false);

// 缺 code
check('缺少 code 参数', send('', $mode, $baseUrl), 'Invalid Request', false);

// 未知动作（宽容模式默认应回执成功）
$unknown = makeCode(['action' => 'some_new_action_v9', 'time' => $now]);
check('未知动作（宽容模式）', send($unknown, $mode, $baseUrl), '1');

// 明文直传：默认必须被拒绝（安全要求）
if ($mode === 'http') {
    $rp = sendRawUrl('action=test', $mode, $baseUrl);
} else {
    $_GET['action'] = 'test';
    $rp = NotifyHandler::receive('');
    $rp = $rp['body'];
    unset($_GET['action']);
}
check('明文直传默认关闭（应拒绝）', $rp, 'Invalid Request', false);

// 显式开启后：无害动作放行
Config::set('uc_notify_plain', 1, 'ucenter');
if ($mode === 'http') {
    $rp2 = sendRawUrl('action=test', $mode, $baseUrl);
} else {
    $_GET['action'] = 'test';
    $rp2 = NotifyHandler::receive('');
    $rp2 = $rp2['body'];
    unset($_GET['action']);
}
check('明文直传开启后 test 放行', $rp2, '1');

// 高危急动作即使开启明文也必须拒绝
if ($mode === 'http') {
    $rp3 = sendRawUrl('action=deleteuser&uid=1&username=admin', $mode, $baseUrl);
} else {
    $_GET['action'] = 'deleteuser';
    $_GET['uid']    = '1';
    $rp3 = NotifyHandler::receive('');
    $rp3 = $rp3['body'];
    unset($_GET['action'], $_GET['uid']);
}
check('明文直传拒绝高危动作(deleteuser)', $rp3, 'Invalid Request', false);
Config::set('uc_notify_plain', 0, 'ucenter');

// 密文中的 '+' 号：UCenter 若未做 urlencode，PHP 会把 '+' 解析成空格导致解密失败
$plusCode = '';
for ($i = 0; $i < 80; $i++) {
    $c = makeCode(['action' => 'test', 'time' => $now, 'n' => $i]);
    if (strpos($c, '+') !== false) {
        $plusCode = $c;
        break;
    }
}
if ($mode === 'http') {
    if ($plusCode !== '') {
        check("裸 '+' 密文不被空格破坏", sendRawCode($plusCode, $mode, $baseUrl), '1');
    } else {
        printf("  [SKIP] %-46s => 本轮未生成含 '+' 的密文\n", "裸 '+' 密文不被空格破坏");
    }
} else {
    printf("  [SKIP] %-46s => 需 HTTP 模式（依赖原始 QUERY_STRING）\n", "裸 '+' 密文不被空格破坏");
}

// ===========================================================
//  四、总开关与动作开关
// ===========================================================
echo "\n[4] 总开关 / 单动作开关\n";

Config::set('uc_notify_enabled', 0, 'ucenter');
check('总开关关闭时不再执行业务', send(makeCode(['action' => 'test', 'time' => $now]), $mode, $baseUrl), '1');
$rec = $db->one("SELECT status FROM uc_notices ORDER BY id DESC LIMIT 1");
check('总开关关闭时状态为 ignored', $rec ? $rec['status'] : '(无记录)', 'ignored');
Config::set('uc_notify_enabled', 1, 'ucenter');

Config::set('uc_notify_actions', ['updateapps' => 0], 'ucenter');
check('单动作关闭仍回执成功', send(makeCode(['action' => 'updateapps', 'time' => $now]), $mode, $baseUrl), '1');
$rec2 = $db->one("SELECT status FROM uc_notices ORDER BY id DESC LIMIT 1");
check('单动作关闭时状态为 ignored', $rec2 ? $rec2['status'] : '(无记录)', 'ignored');
Config::set('uc_notify_actions', [], 'ucenter');

// ===========================================================
//  五、通知记录表
// ===========================================================
echo "\n[5] 通知记录\n";
$total = intval($db->val("SELECT COUNT(*) FROM uc_notices"));
check('通知记录已累计入库', $total > 15 ? '1' : '0', '1');
$dup = $db->one("SELECT action, status, cost_ms, ip FROM uc_notices ORDER BY id DESC LIMIT 1");
check('记录含 action/status/耗时/IP', $dup && $dup['action'] !== '' ? '1' : '0', '1');

// ===========================================================
//  清理自测副作用
// ===========================================================
// 本脚本刻意覆盖了「错误密钥 / 过期 / 拒绝」等异常路径，会产生 warn/error 级日志；
// 这些属于测试噪声，跑完即清，避免影响 tools/selftest.php 的「无 error 日志」断言。
$clearedLog = $db->exec("DELETE FROM logs WHERE type='ucenter' AND created_at >= ? AND level IN ('warn','error')", [$T0])->rowCount();
$clearedNtc = $db->exec("DELETE FROM uc_notices WHERE created_at >= ?", [$T0])->rowCount();
$db->exec("DELETE FROM user_profiles WHERE uid=?", [$TUID]);
$db->exec("DELETE FROM sessions WHERE uid=?", [$TUID]);

// ===========================================================
//  汇总
// ===========================================================
echo "\n（已清理自测噪声：日志 {$clearedLog} 条 / 通知记录 {$clearedNtc} 条）\n";
echo "\n=====================================================\n";
printf(" 结果：通过 %d / 失败 %d\n", $PASS, $FAIL);
if ($FAIL) {
    echo " 失败项：\n";
    foreach ($FAILED as $f) {
        echo "   - $f\n";
    }
}
echo "=====================================================\n";

exit($FAIL ? 1 : 0);
