<?php
/**
 * UIC Open API 调用小工具（零依赖，仅用 curl + JSON）。
 *
 * 本文件被 sdk/examples/ 下的示例复用，演示如何直接对接
 * public/api/index.php 的 Open API（与 docs/API.md 完全一致）。
 *
 * 配置：复制下面 CONFIG 段到各示例顶部，或直接修改本文件。
 */

if (!function_exists('uic_api_call')) {
    // ====== 配置区（改成你自己的环境）======
    define('UIC_API_BASE', 'http://127.0.0.1/api/index.php'); // UIC 的 Open API 地址
    define('UIC_APP_ID', 'YOUR_APP_ID');                       // 后台「应用管理」创建
    define('UIC_APP_SECRET', 'YOUR_APP_SECRET');               // 应用密钥（仅服务端）
    // =========================================

    /**
     * 调用 Open API。
     * @param string $m       模块（user/sso/admin/captcha/passkey/admin_product…）
     * @param string $a       动作
     * @param array  $params  POST 表单参数
     * @param array  $opts    ['cookies'=>关联数组, 'cookie_file'=>路径, 'headers'=>[]]
     * @return array          解码后的 JSON（含 code/message/data）
     */
    function uic_api_call($m, $a, array $params = [], array $opts = [])
    {
        $url = rtrim(UIC_API_BASE, '/') . '?m=' . urlencode($m) . '&a=' . urlencode($a);
        $ch  = curl_init($url);
        $curlOpts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/x-www-form-urlencoded'], $opts['headers'] ?? []),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ];
        // Cookie 处理（用于后台会话 / 用户登录态保持）
        if (!empty($opts['cookie_file'])) {
            $curlOpts[CURLOPT_COOKIEJAR]  = $opts['cookie_file'];
            $curlOpts[CURLOPT_COOKIEFILE] = $opts['cookie_file'];
        } elseif (!empty($opts['cookies'])) {
            $pairs = [];
            foreach ($opts['cookies'] as $k => $v) {
                $pairs[] = $k . '=' . urlencode($v);
            }
            $curlOpts[CURLOPT_COOKIE] = implode('; ', $pairs);
        }
        curl_setopt_array($ch, $curlOpts);
        $body  = curl_exec($ch);
        $errno = curl_errno($ch);
        $err   = curl_error($ch);
        curl_close($ch);
        if ($errno) {
            return ['code' => -1, 'message' => 'curl error: ' . $err, 'data' => null];
        }
        $json = json_decode($body, true);
        if (!is_array($json)) {
            return ['code' => -2, 'message' => '非 JSON 响应：' . substr($body, 0, 200), 'data' => null];
        }
        return $json;
    }

    /** 仅打印调用结果与数据，便于演示 */
    function uic_dump($label, $resp)
    {
        echo "── $label ──\n";
        echo "code: " . ($resp['code'] ?? '?') . " | message: " . ($resp['message'] ?? '') . "\n";
        if (isset($resp['data'])) {
            echo "data: " . json_encode($resp['data'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
        }
        echo "\n";
    }
}
