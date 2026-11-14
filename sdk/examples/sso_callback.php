<?php
/**
 * SSO 回调处理示例
 *
 * 此文件应部署在应用回调地址上，如: http://your-app.local/callback
 */
// 优先 Composer 自动加载，否则用内置零依赖 PSR-4 加载器
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
} else {
    require_once __DIR__ . '/../autoload.php';
}

use UIC\SDK\Client;
use UIC\SDK\Exception\ApiException;

// 1. 初始化客户端
$client = new Client([
    'base_url'   => 'http://127.0.0.5',
    'app_id'     => 'YOUR_APP_ID',
    'app_secret' => 'YOUR_APP_SECRET',
    'token_storage' => __DIR__ . '/runtime/token.json',
]);

// 2. 获取 ticket
$ticket = $_GET['ticket'] ?? '';
$state  = $_GET['state']  ?? '';

// 3. 验证 state（防止 CSRF）
if ($state !== $_SESSION['sso_state'] ?? '') {
    die('State 验证失败，可能存在安全风险');
}

// 4. 用 ticket 换取 token
try {
    $tokenData = $client->auth()->exchangeToken($ticket);
    echo "登录成功！\n";
    echo "Token: " . $tokenData['token'] . "\n";

    // 5. 获取用户信息
    $userInfo = $client->auth()->getUserInfo();
    echo "欢迎, " . ($userInfo['nickname'] ?? $userInfo['username']) . "!\n";

    // 6. 保存到 session
    $_SESSION['uic_token'] = $tokenData['token'];
    $_SESSION['uic_user']  = $userInfo;

} catch (ApiException $e) {
    echo "认证失败: " . $e->getMessage() . " (错误码: " . $e->getCode() . ")\n";
}
