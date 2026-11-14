<?php
// 优先 Composer 自动加载，否则用内置零依赖 PSR-4 加载器
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
} else {
    require_once __DIR__ . '/../autoload.php';
}

use UIC\SDK\Client;

// ================= 基础配置 =================
$config = [
    'base_url'      => 'http://127.0.0.5',
    'app_id'        => 'YOUR_APP_ID',
    'app_secret'    => 'YOUR_APP_SECRET',
    'timeout'       => 30,
    'debug'         => true,
    'token_storage' => __DIR__ . '/runtime/token.json',
];

$client = new Client($config);

// ================= 1. SSO 单点登录流程 =================
echo "=== SSO 单点登录 ===\n";

// 第一步：获取授权跳转 URL（引导用户浏览器跳转）
$authUrl = $client->auth()->getAuthorizeUrl(
    'http://your-app.local/callback',
    'random_state_' . bin2hex(random_bytes(8))
);
echo "授权URL: $authUrl\n";

// 第二步：用户授权回调后，用 ticket 换取 token
// 此步骤在实际应用中应在回调页面处理
// $ticket = $_GET['ticket'] ?? '';
// $tokenData = $client->auth()->exchangeToken($ticket);
// echo "Token: " . $tokenData['token'] . "\n";

// 第三步：使用 token 获取用户信息
// $userInfo = $client->auth()->getUserInfo();
// echo "用户信息: " . json_encode($userInfo, JSON_UNESCAPED_UNICODE) . "\n";

// ================= 2. 通信测试 =================
echo "\n=== 通信测试 ===\n";
$connected = $client->auth()->testConnect();
echo "连接状态: " . ($connected ? '正常' : '失败') . "\n";

// ================= 3. 用户注册（需 Cookie 会话态，适用于代理场景） =================
echo "\n=== 用户注册 ===\n";
try {
    $user = $client->user()->register([
        'username' => 'test_user_' . time(),
        'password' => 'Test@12345',
        'confirm'  => 'Test@12345',
        'email'    => 'test@example.com',
        'mobile'   => '13800138000',
        'nickname' => '测试用户',
    ], true); // auto_login = true
    echo "注册成功: " . json_encode($user, JSON_UNESCAPED_UNICODE) . "\n";
} catch (\Exception $e) {
    echo "注册失败: " . $e->getMessage() . "\n";
}

// ================= 4. 用户登录 =================
echo "\n=== 用户登录 ===\n";
try {
    $loginResult = $client->user()->login('test_user', 'Test@12345', true);
    echo "登录成功: " . json_encode($loginResult, JSON_UNESCAPED_UNICODE) . "\n";
} catch (\Exception $e) {
    echo "登录失败: " . $e->getMessage() . "\n";
}

// ================= 5. 获取当前用户信息 =================
echo "\n=== 当前用户信息 ===\n";
try {
    $info = $client->user()->getInfo();
    echo "用户信息: " . json_encode($info, JSON_UNESCAPED_UNICODE) . "\n";
} catch (\Exception $e) {
    echo "获取失败: " . $e->getMessage() . "\n";
}

// ================= 6. 修改密码 =================
echo "\n=== 修改密码 ===\n";
try {
    $result = $client->user()->changePassword('Test@12345', 'NewPass@456', 'NewPass@456');
    echo "修改成功: " . json_encode($result, JSON_UNESCAPED_UNICODE) . "\n";
} catch (\Exception $e) {
    echo "修改失败: " . $e->getMessage() . "\n";
}

// ================= 7. 图形验证码 =================
echo "\n=== 图形验证码 ===\n";
try {
    $captcha = $client->captcha()->issue('digits');
    echo "验证码Token: " . $captcha['token'] . "\n";
    // 前端展示验证码图片后，用户输入答案
    // $verified = $client->captcha()->verify($captcha['token'], '1234');
    // echo "验证结果: " . ($verified ? '通过' : '失败') . "\n";
} catch (\Exception $e) {
    echo "获取失败: " . $e->getMessage() . "\n";
}

// ================= 8. 账号安全信息 =================
echo "\n=== 账号安全 ===\n";
try {
    $security = $client->user()->getSecurity();
    echo "安全信息: " . json_encode($security, JSON_UNESCAPED_UNICODE) . "\n";
} catch (\Exception $e) {
    echo "获取失败: " . $e->getMessage() . "\n";
}

// ================= 9. 单点退出 =================
echo "\n=== 单点退出 ===\n";
$client->auth()->logout();
echo "已退出\n";

// ================= 10. 唯一性检查 =================
echo "\n=== 唯一性检查 ===\n";
try {
    $available = $client->user()->checkUnique('username', 'new_username_test');
    echo "用户名可用: " . ($available ? '是' : '否') . "\n";
} catch (\Exception $e) {
    echo "检查失败: " . $e->getMessage() . "\n";
}
