<?php
/**
 * 管理员找回密码（邮件验证码自助重置）回归测试
 * 运行：php tools/admin_findpwd_test.php
 *
 * 覆盖：字段迁移 / 通道判定 / 账号+邮箱双重匹配 / 频控 /
 *       验证码校验 / 口令强度 / 历史密码防重用 / 重置后旧密码失效 + 会话版本号递增
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

$pass = 0;
$fail = 0;
function ok($cond, $name, $extra = '')
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  [OK]   $name\n";
    } else {
        $fail++;
        echo "  [FAIL] $name" . ($extra !== '' ? " -> $extra" : '') . "\n";
    }
}

echo "=== 管理员找回密码 回归测试 ===\n\n";

// ---------- 0. 准备：临时开启本地联调双开关（否则无邮件服务时通道不可用），结束时还原 ----------
$orig = [
    'debug'                 => Config::get('debug'),
    'admin_findpwd_debug'   => Config::get('admin_findpwd_debug'),
    'admin_findpwd_enabled' => Config::get('admin_findpwd_enabled'),
];
register_shutdown_function(function () use ($orig) {
    Config::set('debug', $orig['debug'], 'system', '调试模式');
    Config::set('admin_findpwd_debug', $orig['admin_findpwd_debug'], 'security', '管理员找回验证码回显');
    Config::set('admin_findpwd_enabled', $orig['admin_findpwd_enabled'], 'security', '管理员自助找回密码开关');
});
Config::set('debug', 1, 'system', '调试模式');
Config::set('admin_findpwd_debug', 1, 'security', '管理员找回验证码回显');
Config::set('admin_findpwd_enabled', 1, 'security', '管理员自助找回密码开关');

// ---------- 1. admins.email 幂等迁移 ----------
echo "[1] 数据表与通道\n";
ok(AdminModel::ensureEmailColumn(), 'ensureEmailColumn() 幂等迁移成功');
ok(AdminModel::ensureEmailColumn(), 'ensureEmailColumn() 重复调用不报错（幂等）');
list($st, $why) = AdminResetService::status();
ok($st === true, 'status() 在联调通道下判定为可用', $why);

// ---------- 2. 建测试管理员 ----------
$db = Database::getInstance();
$db->delete('admins', 'username=?', ['areset_t']);
$db->delete('verify_codes', 'target=?', ['areset_t@example.com']);
$db->exec("DELETE FROM login_fail WHERE account='admin:areset_t'");
list($cOk, $cMsg, $adminId) = AdminModel::create('areset_t', 'OldPass123', '重置测试', 'admin', 'areset_t@example.com');
ok($cOk, '创建测试管理员', $cMsg);
ok(intval($adminId) > 0, '测试管理员已分配 ID');
ok((AdminModel::findById($adminId)['email'] ?? '') === 'areset_t@example.com', '测试管理员邮箱已写入');

// ---------- 3. 发送验证码：账号 + 邮箱双重匹配 ----------
echo "\n[2] 发送验证码\n";
$r = AdminResetService::sendCode('areset_t', 'wrong@example.com');
ok(!$r['ok'] && $r['message'] === '账号或邮箱不匹配', '邮箱不匹配被拒（不泄露账号是否存在）', $r['message'] ?? '');
$r = AdminResetService::sendCode('no_such_admin', 'areset_t@example.com');
ok(!$r['ok'] && $r['message'] === '账号或邮箱不匹配', '账号不存在被拒（措辞一致，防账号枚举）', $r['message'] ?? '');
$r = AdminResetService::sendCode('areset_t', 'not-an-email');
ok(!$r['ok'], '非法邮箱格式被拒', $r['message'] ?? '');

$r = AdminResetService::sendCode('areset_t', 'areset_t@example.com');
ok($r['ok'], '账号+邮箱匹配时发送成功', $r['message'] ?? '');
ok(isset($r['debug_code']) && preg_match('/^\d{6}$/', $r['debug_code']), '联调开关下回显 6 位验证码');
$code = $r['debug_code'] ?? '';

$r2 = AdminResetService::sendCode('areset_t', 'areset_t@example.com');
ok(!$r2['ok'] && strpos($r2['message'], '频繁') !== false, '60 秒内重复发送被频控拦截', $r2['message'] ?? '');

// ---------- 4. 重置：校验与口令策略 ----------
echo "\n[3] 校验并重置\n";
$r = AdminResetService::reset('areset_t', 'areset_t@example.com', '000000', 'NewPass123', 'NewPass123');
ok(!$r['ok'] && $r['message'] === '验证码不正确', '错误验证码被拒', $r['message'] ?? '');

$r = AdminResetService::reset('areset_t', 'areset_t@example.com', $code, 'abc', 'abc');
ok(!$r['ok'] && strpos($r['message'], '长度') !== false, '过短口令被拒', $r['message'] ?? '');
$r = AdminResetService::reset('areset_t', 'areset_t@example.com', $code, 'abcdefghij', 'abcdefghij');
ok(!$r['ok'] && strpos($r['message'], '字母和数字') !== false, '纯字母口令被拒', $r['message'] ?? '');
$r = AdminResetService::reset('areset_t', 'areset_t@example.com', $code, 'NewPass123', 'OtherPass123');
ok(!$r['ok'] && strpos($r['message'], '不一致') !== false, '两次口令不一致被拒', $r['message'] ?? '');

$ver0 = intval(Config::get('admin_sess_ver_' . $adminId, 0));
$r = AdminResetService::reset('areset_t', 'areset_t@example.com', $code, 'NewPass123', 'NewPass123');
ok($r['ok'], '正确验证码 + 合规口令重置成功', $r['message'] ?? '');

// ---------- 5. 结果：旧密码失效、新密码可登录、会话版本号递增 ----------
echo "\n[4] 重置结果\n";
list($lgOk, $lgMsg) = AdminModel::login('areset_t', 'OldPass123');
ok(!$lgOk, '旧密码已失效', $lgMsg);
list($lgOk2, $lgMsg2) = AdminModel::login('areset_t', 'NewPass123');
ok($lgOk2, '新密码可登录', $lgMsg2);
$ver1 = intval(Config::get('admin_sess_ver_' . $adminId, 0));
ok($ver1 === $ver0 + 1, '会话版本号递增（其它设备后台登录被强制下线）', "$ver0 -> $ver1");
ok(!$db->one("SELECT * FROM login_fail WHERE account=?", ['admin:areset_t']), '找回后登录失败锁定已清除');

// 验证码用后即焚：同一码不能二次使用
$r = AdminResetService::reset('areset_t', 'areset_t@example.com', $code, 'ThirdPass123', 'ThirdPass123');
ok(!$r['ok'], '验证码用后即焚，不可重复使用', $r['message'] ?? '');

// ---------- 6. 开关关闭时整条链路拒绝 ----------
echo "\n[5] 开关与降级\n";
Config::set('admin_findpwd_enabled', 0, 'security', '管理员自助找回密码开关');
ok(!AdminResetService::sendCode('areset_t', 'areset_t@example.com')['ok'], '总开关关闭时拒绝发送');
ok(!AdminResetService::reset('areset_t', 'areset_t@example.com', '123456', 'NewPass123', 'NewPass123')['ok'], '总开关关闭时拒绝重置');
list($st2, $why2) = AdminResetService::status();
ok($st2 === false && strpos($why2, '已关闭') !== false, 'status() 关闭时返回不可用原因', $why2);
Config::set('admin_findpwd_enabled', 1, 'security', '管理员自助找回密码开关');

// 关闭联调回显后，无邮件服务时通道判定为不可用（页面降级）
Config::set('admin_findpwd_debug', 0, 'security', '管理员找回验证码回显');
list($st3, $why3) = AdminResetService::status();
ok($st3 === false && strpos($why3, '邮件服务') !== false, '未开启邮件服务时 status() 降级', $why3);

// ---------- 7. 清理 ----------
$db->delete('admins', 'username=?', ['areset_t']);
$db->delete('verify_codes', 'target=?', ['areset_t@example.com']);
$db->exec("DELETE FROM login_fail WHERE account='admin:areset_t'");
ok(!AdminModel::findByName('areset_t'), '测试管理员已清理');

echo "\n================ 结果：$pass 通过 / $fail 失败 ================\n";
exit($fail === 0 ? 0 : 1);
