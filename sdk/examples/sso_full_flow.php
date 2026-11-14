<?php
/**
 * 示例：完整 SSO 单点登录流程（m=sso）
 * ----------------------------------------------------
 * 步骤：① 引导用户跳转 authorize → ② 浏览器回跳带 ticket
 *      → ③ 服务端用 ticket+app_secret 换 token → ④ 用 token 取资料 → ⑤ 单点退出
 *
 * 运行：
 *   php sdk/examples/sso_full_flow.php            # 仅打印授权 URL（第一步）
 *   php sdk/examples/sso_full_flow.php TICKET     # 用回跳拿到的 ticket 跑完后续
 */
require_once __DIR__ . '/_helper.php';

$callback = 'http://my-app.local/callback';

// ① 构造授权 URL（引导用户跳转；用户在本中心登录后回跳 ?ticket=xxx）
$authUrl = rtrim(UIC_API_BASE, '/') . '?m=sso&a=authorize&app_id=' . urlencode(UIC_APP_ID)
    . '&redirect=' . urlencode($callback);
echo "① 授权 URL：\n$authUrl\n\n";

$ticket = $argv[1] ?? '';
if (!$ticket) {
    echo "（把上面 URL 粘到浏览器登录后，URL 会带 ?ticket=...；再运行本脚本并传入该 ticket 完成后续步骤）\n";
    exit;
}

// ③ 服务端用 ticket + app_secret 换长期 token（一次性）
$exchange = uic_api_call('sso', 'exchange', [
    'ticket'      => $ticket,
    'app_id'      => UIC_APP_ID,
    'app_secret'  => UIC_APP_SECRET,
]);
uic_dump('sso/exchange（ticket 换 token）', $exchange);

$token = $exchange['data']['token'] ?? '';
if (!$token) {
    echo "换 token 失败（ticket 可能过期/已被使用，限时 60s 且一次性）。\n";
    exit;
}

// ④ 用 token 取用户资料
uic_dump('sso/userinfo', uic_api_call('sso', 'userinfo', [
    'token'  => $token,
    'app_id' => UIC_APP_ID,
]));

// ⑤ 单点退出（吊销 token 及关联会话）
uic_dump('sso/logout', uic_api_call('sso', 'logout', [
    'token'  => $token,
    'app_id' => UIC_APP_ID,
]));

echo "完成：用户已在你的应用与本中心之间完成「一次登录，处处通行」。\n";
