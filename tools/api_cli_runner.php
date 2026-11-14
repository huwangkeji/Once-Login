<?php
/**
 * API CLI 运行器（仅限本机命令行测试使用，禁止部署到公网可访问目录）
 *
 * Response::json() 输出后即 exit，因此每个 API 调用必须在独立进程里执行；
 * 本脚本用于 tools/member_product_test.php 等测试以子进程方式驱动 API。
 *
 * 用法：php tools/api_cli_runner.php <m> <a> <paramsFile>
 *   paramsFile：UTF-8 查询串文件（key=value&key2=value2）。
 *   中文参数必须走文件——Windows 下 argv 传中文会被控制台代码页破坏。
 */
$m = preg_replace('/[^a-z_]/', '', $argv[1] ?? 'admin_product');
$a = preg_replace('/[^a-z_\-]/', '', $argv[2] ?? 'list');
$paramsFile = $argv[3] ?? '';

$post = [];
if ($paramsFile !== '' && file_exists($paramsFile)) {
    parse_str((string)file_get_contents($paramsFile), $post);
}

session_id('uic-cli-smoke');
@session_start();
$_SESSION['uic_admin_id']   = 1;
$_SESSION['uic_admin']      = 'cli-smoke';
$_SESSION['uic_admin_role'] = 'super_admin';
$_SESSION['uic_admin_at']   = time();

$_SERVER['HTTP_HOST']      = '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI']    = '/api/index.php';
$_GET['m']  = $m;
$_GET['a']  = $a;
$_POST      = $post;

require dirname(__DIR__) . '/public/api/index.php';
