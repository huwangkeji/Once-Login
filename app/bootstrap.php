<?php
/**
 * 引导 / 自动加载 / 全局初始化
 */
define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
define('DATA_PATH', BASE_PATH . '/data');
define('PUBLIC_PATH', BASE_PATH . '/public');
define('UIC_VERSION', '1.1.0');

date_default_timezone_set('Asia/Shanghai');
mb_internal_encoding('UTF-8');

// =========================================================
//  生产安全基线：错误一律记日志，绝不展示给用户
//  （路径 / 堆栈 / SQL 泄露是上线最常见的低级事故，此处统一兜底，
//   不依赖部署环境的 php.ini 是否配置正确）
// =========================================================
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('html_errors', '0');
ini_set('log_errors', '1');

// 简易自动加载：类名 -> 目录同名文件
spl_autoload_register(function ($class) {
    // 命名空间类：lbuchs\WebAuthn\* -> app/Core/WebAuthn/
    if (strpos($class, 'lbuchs\\WebAuthn\\') === 0) {
        $rel  = substr($class, strlen('lbuchs\\WebAuthn\\'));
        $file = APP_PATH . '/Core/WebAuthn/' . str_replace('\\', '/', $rel) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
        return;
    }
    $map = [
        APP_PATH . '/Core/UCenter/' . $class . '.php',
        APP_PATH . '/Core/Sms/' . $class . '.php',
        APP_PATH . '/Core/' . $class . '.php',
        APP_PATH . '/Model/' . $class . '.php',
        APP_PATH . '/Service/' . $class . '.php',
    ];
    foreach ($map as $f) {
        if (file_exists($f)) {
            require_once $f;
            return;
        }
    }
});

/** 站点根 URL（无 rewrite 场景；settings 表 site_url 优先，其次 config 默认，最后自动推断） */
function site_url($path = '')
{
    $cfgUrl = '';
    try {
        $cfgUrl = (string)Config::get('site_url', '');
    } catch (Exception $e) {
        // 数据库未就绪（安装前 / CLI 场景）时忽略，走 config 默认
    }
    if ($cfgUrl === '') {
        $cfgUrl = (string)(Config::defaults()['site_url'] ?? '');
    }
    if ($cfgUrl !== '') {
        return rtrim($cfgUrl, '/') . '/' . ltrim($path, '/');
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? 80) == 443);
    $scheme = $https ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
    return $scheme . '://' . $host . '/' . ltrim($path, '/');
}

/** HTML 转义 */
function e($str)
{
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

/** 生成随机串（任意长度均精确返回，奇数长度不再截断少一位） */
function rand_str($len = 32)
{
    $len = intval($len);
    if ($len <= 0) {
        return '';
    }
    return substr(bin2hex(random_bytes(intval(($len + 1) / 2))), 0, $len);
}

/** 视图渲染 */
function view($tpl, $vars = [])
{
    $v = $vars;
    extract($vars);
    $file = APP_PATH . '/View/' . $tpl . '.php';
    if (!file_exists($file)) {
        throw new RuntimeException('view not found: ' . $tpl);
    }
    ob_start();
    require $file;
    return ob_get_clean();
}

/** 时间友好格式 */
function human_time($ts)
{
    $ts = intval($ts);
    if (!$ts) {
        return '-';
    }
    $d = time() - $ts;
    if ($d < 60) return $d . ' 秒前';
    if ($d < 3600) return floor($d / 60) . ' 分钟前';
    if ($d < 86400) return floor($d / 3600) . ' 小时前';
    if ($d < 2592000) return floor($d / 86400) . ' 天前';
    return date('Y-m-d H:i', $ts);
}

/** 站点名称（全局可用，从配置读取） */
function site_name()
{
    return Config::get('site_name', '统一身份认证中心');
}

// =========================================================
//  全局兜底异常处理
//  业务代码（API / 控制器）已有 try/catch；此处拦截漏网之鱼，
//  保证：① 记入日志便于排查  ② 用户只看到通用提示，无敏感信息
// =========================================================

/** 把异常写入日志（Logger 依赖 DB，DB 不可用时降级到 error_log，绝不二次抛错） */
function uic_log_throwable($label, Throwable $ex)
{
    $msg = $label . ': ' . $ex->getMessage() . ' @ ' . $ex->getFile() . ':' . $ex->getLine();
    try {
        if (class_exists('Logger') && class_exists('Database')) {
            Logger::error('system', $label, ['message' => $ex->getMessage(), 'file' => $ex->getFile(), 'line' => $ex->getLine()]);
            return;
        }
    } catch (Throwable $ignore) {
        // 落库失败则走 error_log
    }
    error_log('[UIC] ' . $msg);
}

/** 面向用户的通用错误页（不含任何内部细节） */
function uic_error_page()
{
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>服务暂时不可用</title>'
        . '<div style="font:15px/1.8 system-ui,sans-serif;max-width:640px;margin:12vh auto;padding:0 20px;color:#333">'
        . '<h1 style="font-size:20px">服务暂时不可用</h1>'
        . '<p>系统处理请求时发生异常，请稍后重试。若持续出现，请联系管理员并提供当前时间。</p>'
        . '</div>';
}

set_exception_handler(function (Throwable $ex) {
    uic_log_throwable('未捕获异常', $ex);
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, '[UIC 未捕获异常] ' . $ex->getMessage() . ' @ ' . $ex->getFile() . ':' . $ex->getLine() . PHP_EOL);
        exit(255);
    }
    uic_error_page();
    exit(1);
});

// 仅做日志增强：返回 false 交回 PHP 默认处理，不改变现有行为（@抑制、notice 级别等）
set_error_handler(function ($no, $str, $file = '', $line = 0) {
    if (error_reporting() & $no) {
        try {
            if (class_exists('Logger') && class_exists('Database')) {
                Logger::warn('system', 'PHP 运行时错误', ['errno' => $no, 'message' => $str, 'file' => $file, 'line' => $line]);
            }
        } catch (Throwable $ignore) {
        }
    }
    return false;
});

// 致命错误（E_ERROR/E_PARSE 等不会走 set_error_handler），在 shutdown 阶段兜底
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err === null) {
        return;
    }
    $fatal = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR;
    if (!($err['type'] & $fatal)) {
        return;
    }
    try {
        if (class_exists('Logger') && class_exists('Database')) {
            Logger::error('system', 'PHP 致命错误', ['message' => $err['message'], 'file' => $err['file'], 'line' => $err['line']]);
        }
    } catch (Throwable $ignore) {
    }
    if (PHP_SAPI !== 'cli') {
        uic_error_page();
    }
});

// 企业级：全局安全响应头（CSP / X-Frame-Options 等；CLI 自检场景自动跳过）
SecureHeaders::send();
