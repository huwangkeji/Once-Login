<?php
/**
 * 设置项分类迁移工具（命令行）
 * =============================================================
 * 用途：把历史 settings 表按「设置项分类大纲（SettingsSchema）」重新归类：
 *   1) 修正 s_group：写入大纲中的模块 id（site/account/security/sso/mail/sms/ucenter/oauth/system）
 *   2) 补写 remark：未填写说明的行用大纲中的中文标签补全
 *   3) 补建缺失键：大纲收录但库中不存在的键，按默认值插入（不覆盖已有值）
 *   4) 输出未收录的历史键清单，便于补充进大纲
 *
 * 用法：
 *   php tools/migrate_settings.php           # 预演（只打印将要执行的变更，不写库）
 *   php tools/migrate_settings.php --apply   # 实际执行
 *   php tools/migrate_settings.php --stats   # 只打印当前分类统计
 *
 * 安全提示：仅命令行可用；执行前建议备份 settings 表。
 * =============================================================
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("[403] Forbidden: 本工具仅限命令行使用\n");
}

$apply = in_array('--apply', $argv, true);
$stats = in_array('--stats', $argv, true);

$db    = Database::getInstance();
$rows  = $db->query("SELECT s_key, s_value, s_group, remark FROM settings ORDER BY s_key");
$byKey = [];
foreach ($rows as $r) {
    $byKey[$r['s_key']] = $r;
}

echo "================ 设置项分类迁移 ================\n";
echo "模式：" . ($apply ? "执行（--apply）" : "预演（未写库）") . "\n";
echo "当前 settings 行数：" . count($rows) . "\n\n";

// ---------- 统计 ----------
$stat = [];
foreach (SettingsSchema::schema() as $mid => $mod) {
    $stat[$mid] = ['title' => $mod['title'], 'sections' => 0, 'keys' => 0, 'in_db' => 0];
    foreach ($mod['sections'] as $sid => $sec) {
        $n = count($sec['keys'] ?? []);
        $stat[$mid]['sections']++;
        $stat[$mid]['keys'] += $n;
        foreach (array_keys($sec['keys'] ?? []) as $k) {
            if (isset($byKey[$k])) {
                $stat[$mid]['in_db']++;
            }
        }
    }
}
echo "---------------- 模块统计 ----------------\n";
printf("%-12s %-14s %6s %6s %8s\n", '模块ID', '模块名', '分组', '定义键', '已入库');
foreach ($stat as $mid => $s) {
    printf("%-12s %-14s %6d %6d %8d\n", $mid, $s['title'], $s['sections'], $s['keys'], $s['in_db']);
}
$others = SettingsSchema::uncategorized($rows);
echo "\n未收录的历史键：" . count($others) . " 个\n";
foreach ($others as $r) {
    echo "  - {$r['s_key']}（原分组 {$r['s_group']}）\n";
}
if ($stats) {
    exit("\n（--stats 仅统计，未做变更）\n");
}

// ---------- 变更计划 ----------
$updates = [];   // [s_key => ['group'=>..., 'remark'=>...]]
$inserts = [];   // [s_key => ['value'=>..., 'group'=>..., 'remark'=>...]]

foreach (SettingsSchema::schema() as $mid => $mod) {
    foreach ($mod['sections'] as $sid => $sec) {
        foreach (($sec['keys'] ?? []) as $k => $def) {
            $label  = (string)($def['label'] ?? $k);
            $exists = isset($byKey[$k]);
            if (!$exists) {
                $inserts[$k] = [
                    'value'  => $def['default'] ?? '',
                    'group'  => $mid,
                    'remark' => $label,
                    'mod'    => $mod['title'],
                    'sec'    => $sec['title'],
                ];
                continue;
            }
            $cur = $byKey[$k];
            $newGroup  = $mid;
            $newRemark = trim((string)$cur['remark']) === '' ? $label : null;
            if ($cur['s_group'] !== $newGroup || $newRemark !== null) {
                $updates[$k] = [
                    'from_group' => $cur['s_group'],
                    'group'      => $newGroup,
                    'remark'     => $newRemark,
                    'mod'        => $mod['title'],
                    'sec'        => $sec['title'],
                ];
            }
        }
    }
}
// 未收录的历史键：归入 system 模块，保证页面「未分类」区可见且不丢项
foreach ($others as $r) {
    if ($r['s_group'] === 'system') {
        continue;
    }
    $updates[$r['s_key']] = [
        'from_group' => $r['s_group'],
        'group'      => 'system',
        'remark'     => null,
        'mod'        => '未分类',
        'sec'        => '历史 / 自定义键值',
    ];
}

echo "\n---------------- 变更计划 ----------------\n";
echo "需更新分组/说明：" . count($updates) . " 项\n";
foreach ($updates as $k => $u) {
    echo sprintf(
        "  * %-26s %s → %s   [%s / %s]%s\n",
        $k, $u['from_group'], $u['group'], $u['mod'], $u['sec'],
        $u['remark'] ? '  (补写说明)' : ''
    );
}
echo "\n需补建默认键：" . count($inserts) . " 项\n";
foreach ($inserts as $k => $i) {
    echo sprintf("  + %-26s = %s   [%s / %s]\n", $k, json_encode($i['value'], JSON_UNESCAPED_UNICODE), $i['mod'], $i['sec']);
}

if (!$apply) {
    exit("\n预演结束。确认无误后执行：php tools/migrate_settings.php --apply\n");
}

// ---------- 执行 ----------
$now = time();
$n1 = 0;
foreach ($updates as $k => $u) {
    if ($u['remark'] !== null) {
        $db->prepare("UPDATE settings SET s_group=?, remark=?, updated_at=? WHERE s_key=?")
           ->execute([$u['group'], $u['remark'], $now, $k]);
    } else {
        $db->prepare("UPDATE settings SET s_group=?, updated_at=? WHERE s_key=?")
           ->execute([$u['group'], $now, $k]);
    }
    $n1++;
}
$n2 = 0;
foreach ($inserts as $k => $i) {
    $json = is_array($i['value']) || is_bool($i['value']) || is_int($i['value'])
        ? json_encode($i['value'], JSON_UNESCAPED_UNICODE)
        : (string)$i['value'];
    $db->prepare("INSERT IGNORE INTO settings(s_key,s_value,s_group,remark,updated_at) VALUES(?,?,?,?,?)")
       ->execute([$k, $json, $i['group'], $i['remark'], $now]);
    $n2++;
}
Config::flush();
echo "\n完成：更新 {$n1} 项，补建 {$n2} 项。\n";
echo "提示：后台「设置中心」将按模块 → 分组 → 设置项三级层级展示。\n";
