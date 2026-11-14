<?php
/**
 * 会员中心改版 + 产品展示后台配置 · 回归测试
 *
 * 覆盖：
 *   1. 个人资料 / 账号安全 两页在登录态下可正常渲染（曾因 Database::all() 不存在而致命错误）
 *   2. member_products 表走统一表前缀
 *   3. 后台 5 个产品 API（list/create/update/toggle/delete）
 *   4. 后台产品管理页渲染 + 前台侧边栏加载已启用产品
 *
 * 用法：php tools/member_product_test.php
 */
$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['SERVER_PORT'] = 80;
require_once dirname(__DIR__) . '/app/bootstrap.php';

$PHP = defined('PHP_BINARY') ? PHP_BINARY : 'php';
$pass = 0;
$fail = 0;

function ok($cond, $name, $extra = '')
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  \033[32mPASS\033[0m $name\n";
    } else {
        $fail++;
        echo "  \033[31mFAIL\033[0m $name" . ($extra ? "  -> $extra" : '') . "\n";
    }
}

/** 调用 API（独立进程，因为 Response::json 会 exit） */
function api($action, $params = '')
{
    global $PHP;
    // 参数走临时文件：Windows 下 argv 传中文会被控制台代码页破坏
    $tmp = sys_get_temp_dir() . '/uic_api_' . getmypid() . '_' . md5($action . $params) . '.txt';
    file_put_contents($tmp, $params);
    $cmd = escapeshellarg($PHP) . ' -d display_errors=0 '
        . escapeshellarg(dirname(__DIR__) . '/tools/api_cli_runner.php')
        . ' admin_product ' . escapeshellarg($action)
        . ' ' . escapeshellarg($tmp) . ' 2>&1';
    $out = shell_exec($cmd);
    @unlink($tmp);
    return json_decode((string)$out, true) ?: ['code' => -1, 'message' => 'bad json: ' . $out];
}

echo "\n=== 1. 表结构与前缀 ===\n";
$db = Database::getInstance();
ok($db->hasTable('member_products'), 'member_products 按当前前缀存在',
    Database::tableName('member_products'));
ok(strpos(Database::tableName('member_products'), Database::prefix()) === 0, '表名带统一前缀');

echo "\n=== 2. 会员中心页面渲染（登录态） ===\n";
$u  = $db->one("SELECT * FROM uc_members ORDER BY uid ASC LIMIT 1");
$uid = intval($u['uid']);
$user = UserService::info($uid);
$base = rtrim(site_url(''), '/');

set_error_handler(function ($no, $str, $file, $line) {
    throw new ErrorException($str, 0, $no, $file, $line);
});

$profileHtml = '';
try {
    $vars = ['title' => '个人资料', 'nav' => 'profile', 'base' => $base, 'user' => $user];
    $vars['content'] = view('portal/profile', $vars);
    $profileHtml = view('layout/member', $vars);
    ok(true, 'profile 渲染无致命错误');
} catch (Throwable $e) {
    ok(false, 'profile 渲染无致命错误', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

$secHtml = '';
try {
    $vars = [
        'title' => '账号安全', 'nav' => 'security', 'base' => $base, 'user' => $user,
        'sec'        => UserService::security($uid),
        'oauthList'  => OAuth::enabled(),
        'oauthBinds' => OAuthService::bindings($uid),
        'deletion'   => DeletionService::status($uid),
        'deletionOn' => DeletionService::enabled(),
    ];
    $vars['content'] = view('portal/security', $vars);
    $secHtml = view('layout/member', $vars);
    ok(true, 'security 渲染无致命错误');
} catch (Throwable $e) {
    ok(false, 'security 渲染无致命错误', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

ok(strpos($profileHtml, 'mc-side') !== false, 'profile 使用两栏布局 .mc-side');
ok(strpos($secHtml, 'mc-side') !== false, 'security 使用两栏布局 .mc-side');
ok(strpos($profileHtml, 'mh-brand') !== false && strpos($profileHtml, '退出登录') !== false, '顶部通栏含品牌与退出登录');
ok(strpos($profileHtml, '帮助和反馈') !== false, '顶部通栏含帮助和反馈入口');
ok(strpos($profileHtml, '个人资料') !== false && strpos($profileHtml, '账号安全') !== false, '侧边栏含两项导航');
ok(strpos($profileHtml, '高级账号') === false && strpos($profileHtml, '邀请码') === false
  && strpos($profileHtml, '>订单<') === false, '侧边栏无高级账号/订单/邀请码');
ok(strpos($profileHtml, 'mc-row') !== false && strpos($profileHtml, '用户 ID') !== false, '资料页为行式条目');
ok(strpos($profileHtml, 'c=page&amp;a=terms') !== false, '侧边栏含服务条款链接');
ok(strpos($profileHtml, 'c=page&amp;a=privacy') !== false, '侧边栏含隐私政策链接');

echo "\n=== 3. 后台产品 API ===\n";
// 备份真实产品数据（测试结束原样恢复，含原 id），随后清空保证断言确定性
$snapshot = MemberProductModel::all();
foreach ($snapshot as $p) {
    MemberProductModel::delete(intval($p['id']));
}

$r = api('list');
ok(($r['code'] ?? -1) === 0, 'admin_product_list 返回成功', json_encode($r, JSON_UNESCAPED_UNICODE));
ok(empty($r['data']['items']), '初始列表为空');

$r = api('create', 'name=' . urlencode('热铁盒网盘') . '&url=' . urlencode('https://pan.example.com')
    . '&description=' . urlencode('安全可靠的云存储') . '&icon=cloud&sort_order=10&enabled=1');
ok(($r['code'] ?? -1) === 0, 'admin_product_create 返回成功', json_encode($r, JSON_UNESCAPED_UNICODE));
$newId = intval($r['data']['id'] ?? 0);
ok($newId > 0, 'create 返回自增 id');

$r = api('create', 'name=' . urlencode('开发者社区') . '&url=' . urlencode('https://bbs.example.com')
    . '&icon=chat&sort_order=5&enabled=0');
$id2 = intval($r['data']['id'] ?? 0);
ok($id2 > 0, 'create 第二条（默认禁用）');

$r = api('list');
$items = $r['data']['items'] ?? [];
ok(count($items) === 2, 'list 返回 2 条', 'got ' . count($items));
ok(intval($items[0]['sort_order']) === 5 && $items[0]['name'] === '开发者社区', 'list 按 sort_order 升序');
ok(intval($items[1]['id']) === $newId, 'list 第二项为网盘');

$r = api('update', 'id=' . $newId . '&name=' . urlencode('热铁盒网盘 Pro') . '&url='
    . urlencode('https://pan.example.com/pro') . '&description=' . urlencode('升级版云存储')
    . '&icon=cloud&sort_order=1&enabled=1');
ok(($r['code'] ?? -1) === 0, 'admin_product_update 返回成功', json_encode($r, JSON_UNESCAPED_UNICODE));
$row = MemberProductModel::get($newId);
ok($row['name'] === '热铁盒网盘 Pro' && intval($row['sort_order']) === 1, 'update 落库正确');

$r = api('toggle', 'id=' . $id2 . '&enabled=1');
ok(($r['code'] ?? -1) === 0 && $r['message'] === '已启用', 'admin_product_toggle 启用', json_encode($r, JSON_UNESCAPED_UNICODE));
ok(intval(MemberProductModel::get($id2)['enabled']) === 1, 'toggle 启用落库');

$r = api('toggle', 'id=' . $id2 . '&enabled=0');
ok($r['message'] === '已禁用' && intval(MemberProductModel::get($id2)['enabled']) === 0, 'toggle 禁用');

$r = api('create', 'name=' . urlencode('') . '&url=' . urlencode('https://x.com'));
ok(($r['code'] ?? 0) !== 0, 'create 空名称被拒绝');

echo "\n=== 4. 前台展示 ===\n";
$enabled = MemberProductModel::listEnabled();
ok(count($enabled) === 1 && $enabled[0]['name'] === '热铁盒网盘 Pro', 'listEnabled 只返回启用项',
    json_encode(array_column($enabled, 'name'), JSON_UNESCAPED_UNICODE));

$vars = ['title' => '个人资料', 'nav' => 'profile', 'base' => $base, 'user' => $user];
$vars['content'] = view('portal/profile', $vars);
$html = view('layout/member', $vars);
ok(strpos($html, '热铁盒网盘 Pro') !== false, '侧边栏渲染已启用产品');
ok(strpos($html, '升级版云存储') !== false, '侧边栏渲染产品描述');
ok(strpos($html, 'https://pan.example.com/pro') !== false, '侧边栏渲染产品链接');
ok(strpos($html, '开发者社区') === false, '禁用产品不出现在侧边栏');
ok(strpos($html, 'mc-app__icon') !== false, '产品磁贴图标节点存在');
ok(strpos($html, 'mc-prod-grid') !== false, '产品磁贴网格存在');

echo "\n=== 5. 后台管理页渲染 ===\n";
try {
    $admin = AdminModel::findById(1) ?: ['id' => 1, 'username' => 'admin', 'role' => 'super_admin'];
    $html = view('admin/products', [
        'title' => '产品展示', 'nav' => 'products', 'base' => $base, 'admin' => $admin,
        'products' => MemberProductModel::all(),
    ]);
    ok(true, 'admin/products 渲染无错误');
    ok(strpos($html, '产品展示管理') !== false, '管理页含标题');
    ok(strpos($html, 'ProductMgr') !== false, '管理页含交互脚本');
} catch (Throwable $e) {
    ok(false, 'admin/products 渲染无错误', $e->getMessage());
}

echo "\n=== 6. 清理与恢复 ===\n";
$r = api('delete', 'id=' . $newId);
ok(($r['code'] ?? -1) === 0, 'admin_product_delete 返回成功');
// 清掉全部测试数据，再原样恢复测试前的真实产品（含原 id）
foreach (MemberProductModel::all() as $p) {
    MemberProductModel::delete(intval($p['id']));
}
foreach ($snapshot as $p) {
    Database::getInstance()->insert('member_products', $p);
}
ok(count(MemberProductModel::all()) === count($snapshot), '真实产品数据已恢复',
    'before=' . count($snapshot) . ' after=' . count(MemberProductModel::all()));

echo "\n----------------------------------------\n";
echo "总计: {$pass} 通过 / {$fail} 失败\n\n";
exit($fail === 0 ? 0 : 1);
