<?php
/**
 * 重置管理员密码（命令行工具）
 * =============================================================
 * 用途：管理员忘记密码 / 需要重置时，直接修改 admins 表密码。
 *
 * 用法：
 *   php tools/reset_admin_pwd.php <username> [<new_password>]
 *   php tools/reset_admin_pwd.php --gen <username>      # 自动生成 16 位随机强密码
 *
 * 参数说明：
 *   <username>    管理员账号（必填）
 *   <new_password>新密码（可选；不填且未加 --gen 时进入交互输入）
 *   --gen         自动生成 16 位随机密码（含大小写字母+数字），仅打印一次，请立即保存
 *
 * 密码规则（与后台一致）：8-32 位，必须同时包含字母和数字。
 * 重置成功后自动清除该管理员的登录失败锁定记录（若之前被锁定可立即登录）。
 *
 * 安全提示：
 *   - 本脚本检测 CLI 运行环境，禁止通过 Web 直接访问（403 拒绝）。
 *   - 不要在共享终端/聊天窗口明文传递生产密码；建议配合 --gen 使用。
 * =============================================================
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("[403] Forbidden: 本工具仅限命令行使用\n");
}

$args = $argv;
array_shift($args); // 去掉脚本名

$useGen   = false;
$rest = [];
foreach ($args as $a) {
    if ($a === '--gen')       { $useGen = true; continue; }
    if (strpos($a, '--') === 0) {
        exit("[错误] 未知参数: $a\n用法: php tools/reset_admin_pwd.php <username> [<new_password>] [--gen]\n");
    }
    $rest[] = $a;
}

$username = $rest[0] ?? '';
$password = $rest[1] ?? '';

if ($username === '') {
    echo "请输入管理员账号: ";
    $username = trim((string)fgets(STDIN));
}
if ($username === '') {
    exit("[错误] 缺少管理员账号\n");
}

try {
    $admin = AdminModel::findByName($username);
} catch (Exception $e) {
    exit("[错误] 数据库连接失败: " . $e->getMessage() . "\n");
}
if (!$admin) {
    exit("[错误] 管理员账号不存在: $username\n");
}

// ---- 密码生成/确认 ----
if ($useGen) {
    $password = rand_str(16);
} elseif ($password === '') {
    echo "请输入新密码（8-32 位，含字母和数字）: ";
    $password = trim((string)fgets(STDIN));
    echo "请再次输入确认: ";
    $confirm = trim((string)fgets(STDIN));
    if ($password !== $confirm) {
        exit("[错误] 两次输入不一致\n");
    }
}

$minLen = max(8, intval(Config::get('security.pwd_min_length', 8)));
$maxLen = intval(Config::get('security.pwd_max_length', 32));
if (strlen($password) < $minLen || strlen($password) > $maxLen) {
    exit("[错误] 新密码长度需为 {$minLen}-{$maxLen} 位\n");
}
if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
    exit("[错误] 新密码必须同时包含字母和数字\n");
}
if (password_verify($password, $admin['password_hash'])) {
    exit("[错误] 新密码不能与当前密码相同\n");
}

// ---- 执行重置 ----
Database::getInstance()->update('admins', [
    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    'updated_at'    => time(),
], 'id=?', [intval($admin['id'])]);

// 重置成功后清除该管理员的登录失败锁定，避免"重置了却仍然被锁"
Database::getInstance()->delete('login_fail', 'account=?', ['admin:' . $username]);

Logger::warn('admin', '管理员密码已通过重置工具修改(CLI)', [
    'admin_id' => intval($admin['id']),
    'username' => $username,
]);

echo "============================================================\n";
echo " 重置成功\n";
echo " 管理员账号: $username\n";
echo " 新密码    : " . ($useGen ? $password : '(由你输入)') . "\n";
echo "============================================================\n";
if ($useGen) {
    echo " 随机密码仅此一次显示，请立即保存！\n";
}