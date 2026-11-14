<?php
/**
 * 静态审计工具：重复方法声明检测
 *
 * 用法：php tools/audit/dup_method.php [目标目录]
 *
 * PHP 在编译期即拒绝同一类内重复声明的方法（Fatal error: Cannot redeclare），
 * 而 `php -l` 无法覆盖所有情形，故用正则做全量扫描，纳入 CI / 上线前自检。
 */
$root = isset($argv[1]) ? rtrim($argv[1], '/\\') : dirname(__DIR__, 2);
$skip = array('.workbuddy', 'vendor', 'node_modules', '.git');

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);
$bad = 0;
$scanned = 0;
foreach ($it as $f) {
    $p = $f->getPathname();
    if (substr($p, -4) !== '.php') { continue; }
    $rel = str_replace('\\', '/', substr($p, strlen($root) + 1));
    foreach ($skip as $s) {
        if (strpos($rel, $s . '/') === 0) { continue 2; }
    }
    $scanned++;
    $src = file_get_contents($p);
    if (!preg_match_all('/^\s*(?:final\s+|abstract\s+)?(?:public|private|protected|static|\s)*function\s+(\w+)\s*\(/mi', $src, $m)) {
        continue;
    }
    $cnt = array_count_values($m[1]);
    foreach ($cnt as $fn => $c) {
        if ($c > 1) {
            echo "DUP_METHOD\t{$rel}\t{$fn}\tx{$c}\n";
            $bad++;
        }
    }
}
echo "scanned={$scanned} duplicate_methods={$bad}\n";
exit($bad > 0 ? 1 : 0);
