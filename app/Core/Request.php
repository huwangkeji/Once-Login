<?php
/**
 * 请求工具
 */
class Request
{
    public static function method()
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public static function isPost()
    {
        return self::method() === 'POST';
    }

    /** 输入取值，支持 json body */
    public static function input($key, $default = null)
    {
        static $jsonBody = null;
        $v = $_POST[$key] ?? $_GET[$key] ?? null;
        if ($v !== null) {
            return is_string($v) ? trim($v) : $v;
        }
        if ($jsonBody === null) {
            $raw = file_get_contents('php://input');
            $jsonBody = json_decode($raw, true) ?: [];
        }
        if (isset($jsonBody[$key])) {
            return is_string($jsonBody[$key]) ? trim($jsonBody[$key]) : $jsonBody[$key];
        }
        return $default;
    }

    public static function all()
    {
        return array_merge($_GET, $_POST);
    }

    public static function route($key, $default = '')
    {
        $map = ['c' => $default, 'a' => 'index'];
        return isset($map[$key]) ? ($_GET[$key] ?? $map[$key]) : ($_GET[$key] ?? '');
    }

    public static function ip()
    {
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'] as $k) {
            if (!empty($_SERVER[$k])) {
                $ip = trim(explode(',', $_SERVER[$k])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '0.0.0.0';
    }

    public static function ua()
    {
        return mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
    }

    public static function bearer()
    {
        $h = '';
        if (function_exists('apache_request_headers')) {
            $hs = @apache_request_headers();
            if ($hs && isset($hs['Authorization'])) {
                $h = $hs['Authorization'];
            }
        }
        if (!$h && !empty($_SERVER['HTTP_AUTHORIZATION'])) {
            $h = $_SERVER['HTTP_AUTHORIZATION'];
        }
        if (!$h && !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $h = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        if (preg_match('/Bearer\s+(.*)$/i', $h, $m)) {
            return trim($m[1]);
        }
        return '';
    }
}
