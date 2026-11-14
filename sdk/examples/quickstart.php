<?php
/**
 * SDK 安装说明
 *
 * 1. 通过 Composer 安装:
 *    composer require uic/sdk
 *
 * 2. 手动安装:
 *    - 将 sdk/ 目录复制到项目中
 *    - require_once 'sdk/vendor/autoload.php'
 *
 * 3. 在后台「应用管理」中创建应用，获取 app_id 和 app_secret
 *
 * 4. 配置回调地址（用于 SSO 授权回调）
 */

// 快速开始：优先 Composer 自动加载，否则用内置零依赖 PSR-4 加载器
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
} else {
    require_once __DIR__ . '/../autoload.php';
}

use UIC\SDK\Client;

$client = new Client([
    'base_url'   => 'http://127.0.0.5',
    'app_id'     => 'YOUR_APP_ID',
    'app_secret' => 'YOUR_APP_SECRET',
]);

// 获取授权 URL，引导用户跳转
$authUrl = $client->auth()->getAuthorizeUrl('http://your-app.local/callback');
header('Location: ' . $authUrl);
exit;
