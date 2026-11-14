<?php
/**
 * 示例：图形验证码人机验证流程（m=captcha）
 * ----------------------------------------------------
 * 演示「生成题目 →（前端渲染并求解）→ 校验答案」的接口调用。
 * 注意：slide/click/icon 的答案需由前端组件 UicCaptcha 渲染后计算，
 *       本 CLI 示例仅展示接口结构与预检校验（无解算，预期校验失败）。
 *
 * 运行：php sdk/examples/captcha_flow.php
 */
require_once __DIR__ . '/_helper.php';

// 1) 生成一道验证题（style 可省略，缺省取后台 captcha_style）
$issue = uic_api_call('captcha', 'issue', ['style' => 'slide']);
uic_dump('captcha/issue（生成题目）', $issue);

if ($issue['code'] === 0) {
    $token = $issue['data']['token'] ?? '';
    echo '拿到 token=' . $token . '（前端据此渲染拼图/点选，并把用户作答通过 captcha/verify 预检）' . "\n\n";

    // 2) 前端即时预检（不消耗 token）：这里用错误答案演示失败返回
    $verify = uic_api_call('captcha', 'verify', [
        'token'  => $token,
        'answer' => '0,0', // 真实场景应为前端计算出的坐标/下标
    ]);
    uic_dump('captcha/verify（预检，预期失败）', $verify);

    echo "说明：登录等最终校验由请求中间件再次调用 CaptchaModel::verify(\$token,\$answer,true) 并消耗 token。\n";
    echo "无感验证(passive)评分未通过时，verify 会返回特殊提示，前端据此刷新为交互式验证码。\n";
}
