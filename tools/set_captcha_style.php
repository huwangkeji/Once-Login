<?php
/**
 * 临时工具：切换验证码样式（测试用）
 * 用法：php tools/_set_cap_style.php slide|digits|click|icon|passive
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';
$style = $argv[1] ?? '';
$allow = array_keys(CaptchaModel::styles());
if (!in_array($style, $allow, true)) {
    exit("用法：php tools/_set_cap_style.php " . implode('|', $allow) . "\n");
}
Config::set('captcha_style', $style);
$cur = Config::get('captcha_style');
echo "captcha_style = {$cur}\n";
