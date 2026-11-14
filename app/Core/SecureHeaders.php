<?php
/**
 * 全局安全响应头（企业级合规基线）
 *
 * 覆盖：
 *   - Content-Security-Policy  内容安全策略（禁止外嵌 frame/object，限制资源加载域）
 *   - X-Frame-Options          DENY 点击劫持防护（双保险与 CSP frame-ancestors 共存）
 *   - X-Content-Type-Options   nosniff 防 MIME 嗅探
 *   - Referrer-Policy          限制 Referer 外泄
 *   - Permissions-Policy       禁用摄像头/麦克风/定位
 *
 * 说明：现有后台/前台视图使用内联脚本与样式，故 CSP 保留 'unsafe-inline'；
 *       进一步收紧需重构全部视图为外链资源（列入后续演进项）。
 * CLI（自检/迁移）下自动跳过，不影响 tools/selftest.php。
 */
class SecureHeaders
{
    public static function send()
    {
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options'        => 'DENY',
            'X-XSS-Protection'       => '1; mode=block',
            'Referrer-Policy'        => 'strict-origin-when-cross-origin',
            'Permissions-Policy'     => 'camera=(), microphone=(), geolocation=()',
        ];
        foreach ($headers as $k => $v) {
            header($k . ': ' . $v);
        }
        header('Content-Security-Policy: '
            . "default-src 'self'; "
            . "script-src 'self' 'unsafe-inline'; "
            . "style-src 'self' 'unsafe-inline'; "
            . "img-src 'self' data: blob:; "
            . "font-src 'self' data:; "
            . "connect-src 'self'; "
            . "object-src 'none'; "
            . "frame-ancestors 'none'; "
            . "base-uri 'self'; "
            . "form-action 'self'");
    }
}