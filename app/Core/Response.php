<?php
/**
 * 统一 API 响应
 */
class Response
{
    public static function json($data = null, $code = 0, $message = 'ok', $httpCode = 200)
    {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        $out = [
            'code'    => $code,
            'message' => $message,
            'data'    => $data,
            'time'    => time(),
        ];
        echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function success($data = null, $message = 'ok')
    {
        self::json($data, 0, $message);
    }

    public static function error($message, $code = 1, $httpCode = 200, $data = null)
    {
        self::json($data, $code, $message, $httpCode);
    }

    /** 供浏览器 <script> 拉取的跨域回调 (UCenter 同步登录兼容) */
    public static function script($js)
    {
        header('Content-Type: application/javascript; charset=utf-8');
        echo $js;
        exit;
    }

    public static function redirect($url, $exit = true)
    {
        header('Location: ' . $url);
        if ($exit) {
            exit;
        }
    }
}
