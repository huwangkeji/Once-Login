<?php
/**
 * 示例：验证码登录流程（m=user &a=send_login_code / login_code）
 * ----------------------------------------------------
 * 适用于「手机号/邮箱 + 短信/邮件验证码」免密登录场景。
 *
 * 运行：php sdk/examples/login_code.php
 * 前置：在 config 中已配置可用短信/邮件网关；调试模式(sms_enabled=0)
 *       下验证码会直接回显在 send_login_code 的响应中，便于联调。
 */
require_once __DIR__ . '/_helper.php';

$TARGET = 'user@example.com'; // 改成真实已注册邮箱/手机号

// 1) 发送验证码（受 IP 策略 + 图形验证码保护；调试模式直接回显 code）
$send = uic_api_call('user', 'send_login_code', ['target' => $TARGET]);
uic_dump('user/send_login_code（发送验证码）', $send);

// 调试模式：从响应里取回显的 code（生产环境请由用户真实输入）
$code = '';
if ($send['code'] === 0 && isset($send['data']['code'])) {
    $code = $send['data']['code'];
    echo "（调试模式）取回验证码：$code\n\n";
} else {
    echo "请在真实环境让用户输入收到的验证码，并在下方手动填入。\n";
    $code = '123456'; // 联调占位
}

// 2) 验证码登录（无需密码）
$login = uic_api_call('user', 'login_code', [
    'target'   => $TARGET,
    'code'     => $code,
    'remember' => 1,
]);
uic_dump('user/login_code（验证码登录）', $login);
