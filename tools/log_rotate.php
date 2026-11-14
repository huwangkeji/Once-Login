<?php
/**
 * 运维清理工具：日志轮转 + 过期数据回收
 *
 * 用法（CLI）：
 *   php tools/log_rotate.php                 # 默认：清理 90 天前的日志 + 各类过期数据
 *   php tools/log_rotate.php --days=30       # 日志保留 30 天
 *   php tools/log_rotate.php --dry-run       # 只统计不删除（先跑这个确认影响面）
 *   php tools/log_rotate.php --keep-uc=30    # uc_calls 出站调用日志保留 30 天
 *
 * 建议加入计划任务（每天凌晨执行）：
 *   Linux crontab : 0 3 * * * /usr/bin/php /path/uic/tools/log_rotate.php --days=90 >> /var/log/uic-rotate.log 2>&1
 *   Windows 计划任务: php.exe D:\www\uic\tools\log_rotate.php --days=90
 *
 * 说明：logs 表是审计/排障依据，保留期不宜过短；等保/审计要求较高的场景建议 180 天。
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

$opt = ['days' => 90, 'keep-uc' => 30, 'dry-run' => false];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z\-]+)(?:=(.*))?$/', $a, $m)) {
        $k = $m[1];
        $v = $m[2] ?? true;
        if ($k === 'dry-run') {
            $opt['dry-run'] = true;
        } elseif (isset($opt[$k])) {
            $opt[$k] = is_numeric($v) ? intval($v) : $v;
        } elseif ($k === 'help') {
            echo "用法: php tools/log_rotate.php [--days=90] [--keep-uc=30] [--dry-run]\n";
            exit(0);
        }
    }
}

$dry   = $opt['dry-run'];
$days  = max(1, intval($opt['days']));
$ucDay = max(1, intval($opt['keep-uc']));
$now   = time();
$db    = Database::getInstance();

echo "\n=== UIC 运维清理 " . ($dry ? '(DRY-RUN 仅统计)' : '(执行删除)') . " ===\n";
echo "日志保留 {$days} 天 / uc_calls 保留 {$ucDay} 天 / 当前 " . date('Y-m-d H:i:s') . "\n\n";

/** 统计或删除，返回影响行数 */
function sweep($db, $label, $table, $where, $args, $dry)
{
    $cnt = intval($db->val("SELECT COUNT(*) FROM {$table} WHERE {$where}", $args));
    if (!$dry && $cnt > 0) {
        $db->exec("DELETE FROM {$table} WHERE {$where}", $args);
    }
    printf("  %-28s %s %d 条\n", $label, $dry ? '待清理' : '已清理', $cnt);
    return $cnt;
}

$total = 0;
try {
    // 1. 日志（审计/操作/UCenter/SSO/API）
    $total += sweep($db, "日志(>{$days}天)", 'logs', 'created_at < ?', [$now - $days * 86400], $dry);

    // 2. UCenter 出站调用日志（量最大，保留期可更短）
    $total += sweep($db, "UCenter 出站调用(>{$ucDay}天)", 'uc_calls', 'created_at < ?', [$now - $ucDay * 86400], $dry);

    // 3. 过期一次性票据（ticket 默认 60 秒即失效）
    $total += sweep($db, '过期 Ticket', 'tickets', 'expires_at < ?', [$now], $dry);

    // 4. 过期访问令牌
    $total += sweep($db, '过期 Token', 'tokens', 'expires_at < ?', [$now], $dry);

    // 5. 过期图形验证码令牌（单次有效、5 分钟）
    $total += sweep($db, '过期验证码令牌', 'captcha_tokens', 'expires_at < ?', [$now], $dry);

    // 6. 过期短信/邮件验证码
    $total += sweep($db, '过期验证码', 'verify_codes', 'expires_at < ? AND expires_at > 0', [$now], $dry);

    // 7. 已过期会话（未撤销的）
    if (!$dry) {
        $n = UserSession::cleanExpired();
        printf("  %-28s %s %d 条\n", '过期会话', '已清理', $n);
        $total += $n;
    } else {
        $n = intval($db->val("SELECT COUNT(*) FROM sessions WHERE expires_at < ? AND revoked = 0", [$now]));
        printf("  %-28s %s %d 条\n", '过期会话', '待清理', $n);
        $total += $n;
    }
} catch (Throwable $e) {
    fwrite(STDERR, "清理失败: " . $e->getMessage() . "\n");
    exit(1);
}

echo "\n合计 " . ($dry ? '待清理' : '已清理') . " {$total} 条\n";

if (!$dry) {
    try {
        Logger::info('system', '运维清理执行', ['days' => $days, 'uc_days' => $ucDay, 'removed' => $total]);
    } catch (Throwable $ignore) {
        // 落库失败不影响清理结果
    }
}
echo ($dry ? "（本次为演练，未删除任何数据；去掉 --dry-run 即生效）" : "完成。") . "\n";
