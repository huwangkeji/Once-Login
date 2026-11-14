<?php
/**
 * 静态自检：全量语法检查
 *
 * 覆盖 php -l（语法 + PHP 8 编译期的重复方法声明）。
 * 用法：php tools/audit/syntax.php [根目录]
 */
$root = isset($argv[1]) ? rtrim($argv[1], '/\\') : dirname(__DIR__, 2);

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

$php = defined('PHP_BINARY') ? PHP_BINARY : 'php';
$bad = 0;
$total = 0;

foreach ($it as $f) {
    $p = $f->getPathname();
    if (substr($p, -4) !== '.php') {
        continue;
    }
    $rel = str_replace('\\', '/', substr($p, strlen($root) + 1));
    if (preg_match('#^(\.workbuddy|vendor|node_modules)/#', $rel)) {
        continue;
    }
    $total++;
    $out = array();
    $rc  = 0;
    exec('"' . $php . '" -l ' . escapeshellarg($p) . ' 2>&1', $out, $rc);
    if ($rc !== 0) {
        echo "SYNTAX_ERROR\t{$rel}\n";
        foreach ($out as $line) {
            echo "    " . trim($line) . "\n";
        }
        $bad++;
    }
}

echo "scanned={$total} syntax_errors={$bad}\n";
exit($bad > 0 ? 1 : 0);
