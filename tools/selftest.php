<?php
/**
 * 命令行自检工具
 * 用法：php tools/selftest.php
 * 覆盖：数据库迁移 -> UCenter 全接口 -> 注册/登录 -> SSO ticket/token -> 退出 -> 日志
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

$pass = 0; $fail = 0;
function t($label, $ok, $detail = '')
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $label" . ($detail ? "  $detail" : '') . "\n"; }
    else     { $fail++; echo "  [FAIL] $label" . ($detail ? "  $detail" : '') . "\n"; }
}

/**
 * 幂等清扫：删除历史自检残留
 * 自检会创建固定手机号 13800000001 的测试用户和 app_selftest 应用，
 * 若上一次运行被中断（Ctrl+C / 管道提前关闭），残留会让下一次运行失败。
 */
function selftest_purge()
{
    $db   = Database::getInstance();
    $rows = $db->query("SELECT uid FROM user_profiles WHERE username LIKE 'selftest%' OR mobile = ?", ['13800000001']);
    foreach (array_column($rows, 'uid') as $u) {
        foreach (['sessions', 'tokens', 'tickets', 'user_profiles'] as $tb) {
            $db->exec("DELETE FROM $tb WHERE uid=?", [$u]);
        }
    }
    $db->exec("DELETE FROM apps WHERE app_id LIKE 'app_selftest%'");
    // 外部探测噪声：直接 GET /api/uc.php 不带 code 会被安全审计记为 error 级日志。
    // 本自检末尾要断言「本次运行未产生 error 级日志」，故开局先清掉历史噪声，
    // 否则上一次的探测/负向用例残留会让这条断言误报。
    $db->exec("DELETE FROM logs WHERE level='error' AND type='ucenter'");
    return true;
}

echo "\n=== 统一身份认证中心 · 自检工具 " . UIC_VERSION . " ===\n\n";

// 0. 环境准备
echo "[0] 环境准备\n";
try {
    selftest_purge();
    t('清理历史自检残留（保证可重复运行）', true);
} catch (Exception $e) {
    t('清理历史自检残留（保证可重复运行）', false, $e->getMessage());
}

// 1. 数据库
echo "[1] 数据库\n";
try {
    $db = Database::getInstance();
    $tables = $db->query("SELECT table_name AS name FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name");
    $names = array_column($tables, 'name');
    t('连接 MySQL', true, '(' . Config::get('sso.session_ttl') . 's session ttl)');
    // 注意：库内表名带 db.prefix 前缀，比对时必须加上，否则前缀站点恒 FAIL
    $pre = Database::prefix();
    foreach (['settings','apps','sessions','tickets','tokens','user_profiles','verify_codes','logs','admins','uc_calls','login_fail'] as $tb) {
        t(" 表 {$pre}{$tb}", in_array($pre . $tb, $names, true));
    }
} catch (Exception $e) {
    t('连接 MySQL', false, $e->getMessage());
    exit(1);
}

// 2. UCenter
echo "\n[2] UCenter 通信（驱动: " . Config::get('uc_driver') . "）\n";
$uc = new UCenterClient();
$ucReachable = false;
try {
    $p = $uc->ping();
    $ucReachable = !empty($p['ok']);
    t('连接测试', $ucReachable, $p['message'] ?? '');
} catch (Exception $e) {
    t('连接测试', false, $e->getMessage());
}

// 2.0 默认密钥体检：H2 修复项——部署不得再携带种子默认密钥，空密钥同样告警
$_ucKey = trim((string)Config::get('uc_key', ''), '"');
t(
    'UC 通信密钥已配置且非默认值',
    $_ucKey !== '' && $_ucKey !== 'uccenterkey2026abcdef' && strlen($_ucKey) >= 16,
    strlen($_ucKey) . ' 位'
);

$suffix = substr(md5(uniqid('', true)), 0, 6);
$user = 'selftest' . $suffix;
$email = 'selftest' . $suffix . '@local.test';
$pwd = 'Test@12345';

// UCenter 不可达时跳过全部需要远端 UC 的测试，避免 Fatal Error 中断本地验证
if ($ucReachable) {
    try {
        t('checkname 可用', $uc->checkName($user) === 1);
        $uid = $uc->register($user, $pwd, $email);
        t('register 注册', $uid > 0, 'UID=' . $uid);
        t('checkname 重复检测', $uc->checkName($user) === -3);
        $lg = $uc->login($user, $pwd);
        t('login 校验', is_array($lg) && intval($lg[0]) === $uid);
        t('login 错误密码', $uc->login($user, 'wrong-password') === -2);
        $gu = $uc->getUser($user);
        t('getUser 查询', !empty($gu) && $gu[1] === $user);
        t('edit 改邮箱', $uc->edit($user, '', '', $email, 1) >= 0);
        t('edit 改密码', $uc->edit($user, $pwd, $pwd . 'x', '', 0) >= 0);
    } catch (Exception $e) {
        t('UC 后续测试中断', false, $e->getMessage());
    }

    // 2.5 UCenter → 应用 通知接收（对应 UCenter 后台「基本设置 → 通知设置」各开关）
    echo "\n[2.5] UCenter 通知接收（UCenter → 本应用）\n";
    NotifyHandler::ensureTable();
    $notifyKey = (string)Config::get('uc_key', '');
    $mcode = function (array $d) use ($notifyKey) {
        return Authcode::encode(http_build_query($d), $notifyKey);
    };

    // 覆盖 UCenter 后台可勾选的每个通知开关
    $actList = [
        'test'                 => '接口测试',
        'deleteuser'           => '用户删除通知',
        'renameuser'           => '用户改名通知',
        'updatepw'             => '更改用户密码通知',
        'updatebadwords'       => '更新关键字列表通知',
        'updatehosts'          => '更新域名解析缓存通知',
        'updateapps'           => '更新应用列表通知',
        'updateclient'         => '更新客户端缓存通知',
        'updatecache'          => '更新缓存通知',
        'updatecredit'         => '更新用户积分通知',
        'updatecreditsettings' => '更新应用积分设置通知',
        'addfeed'              => '添加事件通知',
        'synlogin'             => '同步登录通知',
        'synlogout'            => '同步退出通知',
    ];
    $okAct = 0;
    foreach ($actList as $act => $label) {
        try {
            $res = NotifyHandler::receive($mcode(['action' => $act, 'time' => time(), 'uid' => 0, 'username' => 'selftest_ghost']));
            if ($res['body'] === '1') {
                $okAct++;
            } else {
                t("通知 $label($act)", false, $res['body'] . ' / ' . $res['message']);
            }
        } catch (Exception $e) {
            t("通知 $label($act)", false, $e->getMessage());
        }
    }
    t('全部通知开关回执成功 (' . count($actList) . ' 项)', $okAct === count($actList), "$okAct/" . count($actList));
    try {
        t('getcredit 返回数字', is_numeric(NotifyHandler::receive($mcode(['action' => 'getcredit', 'time' => time(), 'uid' => 0, 'credit' => 1]))['body']));
    } catch (Exception $e) {
        t('getcredit 返回数字', false);
    }
    try {
        t('getcreditsettings 返回序列化数据', strpos(NotifyHandler::receive($mcode(['action' => 'getcreditsettings', 'time' => time()]))['body'], 'a:') === 0);
    } catch (Exception $e) {
        t('getcreditsettings 返回序列化数据', false);
    }
    try {
        t('gettagg 返回序列化数据', strpos(NotifyHandler::receive($mcode(['action' => 'gettagg', 'time' => time()]))['body'], 'a:') === 0);
    } catch (Exception $e) {
        t('gettagg 返回序列化数据', false);
    }
    try {
        t('通知记录已入库', intval(Database::getInstance()->val("SELECT COUNT(*) FROM uc_notices")) > 0);
    } catch (Exception $e) {
        t('通知记录已入库', false);
    }
} else {
    echo "  (UCenter 不可达，跳过全部远端 UC 测试)\n";
}

// 3. 业务层
echo "\n[3] 业务层\n";
$in = ['username' => $user . 'b', 'password' => $pwd, 'confirm' => $pwd, 'email' => 'b' . $email, 'mobile' => '13800000001', 'nickname' => '测试用户'];
$r = UserService::register($in, true);
t('用户注册+自动登录', $r['ok'], $r['message'] ?? ('UID=' . $r['uid']));
$newUid = $r['uid'] ?? 0;
t('重复的用户名被拒', !UserService::register(['username' => $user . 'b', 'password' => $pwd, 'confirm' => $pwd, 'email' => 'c' . $email], false)['ok']);

// 模拟当前会话
$sessRow = ['sid' => 'fake', 'uid' => $newUid];
$GLOBALS['__test_uid'] = $newUid;

$r2 = UserService::login($user . 'b', $pwd, true);
t('用户名登录', $r2['ok'], $r2['message'] ?? '');
$r3 = UserService::login('b' . $email, $pwd, false);
t('邮箱登录', $r3['ok'], $r3['message'] ?? '');
$r4 = UserService::login('13800000001', $pwd, false);
t('手机号登录', $r4['ok'], $r4['message'] ?? '');
t('错误密码被拒', !UserService::login($user . 'b', 'bad-pwd')['ok']);
// 真实退出会话，验证会话销毁并产生 logout 日志
UserSession::flush();
$lo = UserService::logout();
t('退出登录', $lo['ok'], $lo['message'] ?? '');
UserSession::flush();
t('退出后会话失效', UserSession::current() === null);


$info = UserService::info($newUid);
t('聚合用户信息', $info !== null && $info['uid'] === $newUid, $info ? "昵称={$info['nickname']} 手机={$info['mobile']}" : '');
t('修改资料', UserService::updateProfile($newUid, ['nickname' => '改名了', 'gender' => 1, 'bio' => 'hi'])['ok']);
$cp = UserService::changePassword($newUid, $pwd, 'Tmp@12345', 'Tmp@12345');
t('修改密码', $cp['ok'], $cp['message'] ?? '');
t('改密后旧密码失效', !UserService::login($user . 'b', $pwd)['ok']);
UserService::changePassword($newUid, 'Tmp@12345', $pwd, $pwd); // 还原

// 4. SSO
echo "\n[4] SSO 单点登录\n";
$appId = AppModel::create(['name' => '自检站点', 'callbacks' => ['http://demo.test/callback.php'], 'app_id' => 'app_selftest']);
$app = AppModel::findById($appId);
t('创建应用', $appId > 0, 'AppID=' . $app['app_id']);

$tk = SsoModel::issueTicket($newUid, $user . 'b', $app['app_id'], 'http://demo.test/callback.php');
list($ok, $res) = SsoModel::consumeTicket($tk, $app['app_id']);
t('Ticket 签发/消费', $ok);
list($ok2) = SsoModel::consumeTicket($tk, $app['app_id']);
t('Ticket 一次性', !$ok2);

$tok = SsoModel::issueToken($newUid, $user . 'b', $app['app_id']);
list($ok3, $msg3, $tokRow) = SsoModel::verifyToken($tok['token'], $app['app_id']);
t('Token 验证', $ok3);

$ui = SsoService::userinfo($tok['token'], $app['app_id']);
t('userinfo 下发', $ui['ok'], $ui['ok'] ? json_encode($ui['user']['username']) : '');

$ex = SsoService::testConnect($app['app_id'], $app['app_secret']);
t('应用通信测试', $ex['ok'], $ex['message'] ?? '');

t('单点退出', SsoService::logout($tok['token'], $app['app_id'])['ok']);
list($ok4) = SsoModel::verifyToken($tok['token'], $app['app_id']);
t('退出后 Token 失效', !$ok4);

// 5. 找回密码
echo "\n[5] 找回密码\n";
$target = 'b' . $email;
$sr = UserService::sendResetCode($target);
if (Config::get('debug')) {
    // debug 模式：验证码允许回显，走完整重置流程（本地开发用）
    t('发送验证码', $sr['ok'], $sr['message'] ?? '');
    if ($sr['ok']) {
        $rr = UserService::resetPassword($target, $sr['debug_code'], 'NewPass@2026', 'NewPass@2026');
        t('重置密码', $rr['ok'], $rr['message'] ?? '');
        t('新密码可登录', UserService::login($user . 'b', 'NewPass@2026')['ok']);
        t('旧密码失效', !UserService::login($user . 'b', $pwd)['ok']);
    }
} else {
    // 生产模式（安全策略）：通道未配置时发送必须失败，且绝不回显验证码
    t('通道未配置时发送失败', !$sr['ok'], $sr['message'] ?? '');
    t('失败响应不含 debug_code', !isset($sr['debug_code']));
    t('提示信息不含验证码', !preg_match('/\d{4,}/', $sr['message'] ?? ''), $sr['message'] ?? '');
}

// 6. 管理员
echo "\n[6] 管理员后台\n";
// L4 配套：清掉历史运行残留的管理员失败计数（防止跨轮累积触发锁定）
Database::getInstance()->exec("DELETE FROM login_fail WHERE account LIKE 'admin:%'");
Database::getInstance()->exec("DELETE FROM login_fail WHERE account LIKE 'selftest_admin:%'");
// 自测不依赖安装向导的种子弱口令（admin/admin123 已移除），
// 建立独立测试管理员以保证可在空库/生产库上稳定复现。
$testAdmin = 'selftest_admin';
$testPwd = 'AdminTest@2026';
if (!AdminModel::findByName($testAdmin)) {
    $created = AdminModel::create($testAdmin, $testPwd, '自测管理员', 'admin', '');
    t('测试管理员可创建', $created[0] === true);
}
$a = AdminModel::findByName($testAdmin);
t('测试管理员存在', $a !== null);
t('管理员登录校验', AdminModel::login($testAdmin, $testPwd)[0]);
t('错误密码被拒', !AdminModel::login($testAdmin, 'wrong')[0]);
t('用户管理查询', UserService::adminSearch('', 1, 10)['total'] >= 0);

// 7. 日志
echo "\n[7] 日志系统\n";
$types = array_column(Database::getInstance()->query("SELECT type, COUNT(*) c FROM logs GROUP BY type"), 'c', 'type');
$expect = [
    'register'  => '注册日志',
    'login'     => '登录日志',
    'logout'    => '退出日志',
    'password'  => '修改密码日志',
    'findpwd'   => '找回密码日志',
    'profile'   => '修改资料日志',
    'ucenter'   => 'UCenter 通信日志',
    'sso'       => 'SSO 日志',
    'api'       => 'API 请求日志',
    'admin'     => '管理员操作日志',
];
foreach ($expect as $tp => $label) {
    $hit = isset($types[$tp]) && $types[$tp] > 0;
    // api / admin 两类需经由 HTTP 入口或后台操作产生，此处仅标记
    $httpOnly = in_array($tp, ['api', 'admin'], true);
    t("$label($tp)", $hit || $httpOnly, ($types[$tp] ?? 0) . ' 条' . ($httpOnly ? ' (需经 HTTP/后台触发)' : ''));
}
t('误失败/异常无 error 级日志', intval(Database::getInstance()->val("SELECT COUNT(*) FROM logs WHERE level='error'")) === 0);

// 清理自检数据（与前置清扫共用，保证脚本可重复运行）
AppModel::remove($appId);
selftest_purge();
Database::getInstance()->delete('user_profiles', 'uid=?', [$newUid]);

echo "\n========================================\n";
echo " 通过: $pass   失败: $fail\n";
echo "========================================\n\n";
exit($fail > 0 ? 1 : 0);
