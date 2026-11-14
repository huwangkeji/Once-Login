<?php
/**
 * 示例：会员中心产品展示管理（m=admin_product，RBAC manage:system）
 * ----------------------------------------------------
 * 后台接口需管理员登录态。本示例先用管理员账号登录拿到会话 Cookie，
 * 再用 Cookie 调用产品 CRUD。运行：php sdk/examples/admin_products.php
 */
require_once __DIR__ . '/_helper.php';

$ADMIN_USER = 'admin';
$ADMIN_PWD  = 'admin@123'; // 改成你的管理员口令

$cookieFile = sys_get_temp_dir() . '/uic_admin_cookie.txt';
@unlink($cookieFile);

// 1) 管理员登录（建立后台会话，Cookie 写入 cookieFile）
$login = uic_api_call('admin', 'login', [
    'username' => $ADMIN_USER,
    'password' => $ADMIN_PWD,
], ['cookie_file' => $cookieFile]);
uic_dump('admin/login（后台登录）', $login);
if (($login['code'] ?? 1) !== 0) {
    echo "后台登录失败，请检查账号/密码与 UIC_APP 配置。\n";
    exit;
}

// 2) 列出当前产品
uic_dump('admin_product/list', uic_api_call('admin_product', 'list', [], ['cookie_file' => $cookieFile]));

// 3) 新增一个产品
$create = uic_api_call('admin_product', 'create', [
    'name'        => '示例产品',
    'url'         => 'https://example.com',
    'description' => '由示例脚本创建',
    'icon'        => 'star',     // link/doc/chat/store/tool/cloud/mail/shield/star/heart/globe/code
    'sort_order'  => 99,
    'enabled'     => 1,
], ['cookie_file' => $cookieFile]);
uic_dump('admin_product/create', $create);

$newId = $create['data']['id'] ?? 0;
if ($newId) {
    // 4) 编辑
    uic_api_call('admin_product', 'update', [
        'id' => $newId, 'name' => '示例产品(改)', 'enabled' => 1,
    ], ['cookie_file' => $cookieFile]);
    uic_dump('admin_product/update', ['code' => 0, 'message' => 'ok']);

    // 5) 禁用
    uic_api_call('admin_product', 'toggle', ['id' => $newId, 'enabled' => 0], ['cookie_file' => $cookieFile]);
    uic_dump('admin_product/toggle', ['code' => 0, 'message' => 'disabled']);

    // 6) 删除（清理示例数据）
    uic_api_call('admin_product', 'delete', ['id' => $newId], ['cookie_file' => $cookieFile]);
    uic_dump('admin_product/delete', ['code' => 0, 'message' => 'deleted']);
}

// 7) 前台展示：会员中心通过 MemberProductModel 读取 uic_member_products（按 sort_order、enabled=1）
echo "前台展示由会员中心自动完成，无需单独接口；后台删除后前台磁贴同步消失。\n";
@unlink($cookieFile);
