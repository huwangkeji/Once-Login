<?php
/**
 * 零依赖 PSR-4 自动加载器（UIC\SDK → src/）
 * ----------------------------------------------------
 * 当未通过 Composer 安装时，直接 require 本文件即可使用 SDK：
 *   require_once __DIR__ . '/autoload.php';
 *   use UIC\SDK\Client;
 * 若已 composer install，则优先使用 vendor/autoload.php（下方自动回退）。
 */
if (class_exists('UIC\SDK\Client', false)) {
    return; // 已被 Composer 加载，跳过
}

spl_autoload_register(function ($class) {
    $prefix = 'UIC\\SDK\\';
    $base   = __DIR__ . '/src/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    $rel  = substr($class, $len);
    $path = $base . str_replace('\\', '/', $rel) . '.php';
    if (is_file($path)) {
        require $path;
    }
});
