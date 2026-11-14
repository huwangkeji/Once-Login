<?php
/**
 * 管理员后台入口
 * 路由：admin.php?c={module}
 * 企业级：RBAC 路由门禁 + 会话管理 + 安全审计 + 个人安全中心
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
SsoModel::gc();

$c = preg_replace('/[^a-z_]/', '', strtolower(Request::input('c', 'dashboard')));
$base = rtrim(site_url(''), '/');
$admin = AdminModel::current();

/** 渲染后台内页 */
function render($tpl, $vars = [])
{
    global $base, $admin, $c;
    $vars['base']  = $base;
    $vars['admin'] = $admin;
    $vars['nav']   = isset($vars['nav']) ? $vars['nav'] : $GLOBALS['c'];
    if (empty($admin)) {
        $vars['nav'] = 'login';
    }
    $vars['content'] = view($tpl, $vars);
    // 已登录 → 开发者控制台外壳（左侧多级导航 + 顶部搜索/账号区 + 右侧内容区）
    // 未登录 → 简洁居中认证布局（登录页）
    echo view(empty($admin) ? 'layout/auth' : 'layout/console', $vars);
    exit;
}

// 未登录只允许 login / findpwd（后台管理员找回密码）
if (!$admin && $c !== 'login' && $c !== 'findpwd') {
    Response::redirect($base . '/admin.php?c=login');
}

$uc = new UCenterClient();
$mockNotice = '';
if ($admin && Config::get('uc_driver') === 'mock') {
    $mockNotice = '当前 UCenter 使用 <b>本地模拟驱动</b>，账号数据写入主库 ' . htmlspecialchars(Database::tableName('uc_members'))
        . ' 表，仅供开发调试。上线前请在「UCenter 配置」中将驱动切换为 http。';
}

switch ($c) {
    case 'login':
        if ($admin) Response::redirect($base . '/admin.php');
        render('admin/login', ['title' => '后台登录', 'notice' => '']);

    case 'findpwd':
        // 后台管理员找回密码（邮件验证码自助重置）；已登录无需此页
        if ($admin) Response::redirect($base . '/admin.php');
        list($fpOk, $fpWhy) = AdminResetService::status();
        render('admin/findpwd', [
            'title'           => '找回密码',
            'notice'          => '',
            'enabled'         => $fpOk,
            'disabled_reason' => $fpWhy,
            'contact_email'   => AdminResetService::contactEmail(),
        ]);

    case 'changepwd':
        if (!$admin) Response::redirect($base . '/admin.php?c=login');
        render('admin/changepwd', ['title' => '修改密码', 'notice' => $mockNotice]);

    case 'dashboard':
        $db   = Database::getInstance();
        $ping = $uc->ping();
        $stats = [
            'users'    => intval($db->val("SELECT COUNT(*) FROM user_profiles")),
            'sessions' => intval($db->val("SELECT COUNT(*) FROM sessions WHERE revoked=0 AND expires_at>" . time())),
            'apps'     => intval($db->val("SELECT COUNT(*) FROM apps")),
            'tokens'   => intval($db->val("SELECT COUNT(*) FROM tokens WHERE revoked=0 AND expires_at>" . time())),
            'today_reg'=> intval($db->val("SELECT COUNT(*) FROM logs WHERE type='register' AND created_at>" . strtotime('today'))),
            'today_log'=> intval($db->val("SELECT COUNT(*) FROM logs WHERE type='login' AND created_at>" . strtotime('today'))),
            'logs'     => intval($db->val("SELECT COUNT(*) FROM logs")),
            'ucalls'   => intval($db->val("SELECT COUNT(*) FROM uc_calls")),
            // 企业级安全概览
            'fail_today' => intval($db->val("SELECT COUNT(*) FROM logs WHERE level='warn' AND type='login' AND created_at>" . strtotime('today'))),
            'locked_now' => intval($db->val("SELECT COUNT(*) FROM login_fail WHERE lock_until>" . time())),
            'admins'     => intval($db->val("SELECT COUNT(*) FROM admins WHERE status=1")),
            'totp_users' => intval($db->val("SELECT COUNT(*) FROM settings WHERE s_key LIKE 'admin_totp_enabled_%' AND s_value='1'")),
        ];
        render('admin/dashboard', ['title' => '控制台', 'notice' => $mockNotice, 'stats' => $stats, 'ping' => $ping]);

    case 'users':
        Rbac::gate($admin, 'view:users');
        $kw   = Request::input('kw', '');
        $page = intval(Request::input('page', 1));
        $data = UserService::adminSearch($kw, $page, 20);
        render('admin/users', ['title' => '用户管理', 'notice' => $mockNotice, 'data' => $data, 'kw' => $kw]);

    case 'apps':
        Rbac::gate($admin, 'view:apps');
        $apps = AppModel::all();
        render('admin/apps', ['title' => '应用管理', 'notice' => $mockNotice, 'apps' => $apps]);

    case 'sessions':
        Rbac::gate($admin, 'view:sessions');
        render('admin/sessions', ['title' => '会话管理', 'notice' => $mockNotice]);

    case 'audit':
        Rbac::gate($admin, 'view:audit');
        render('admin/audit', ['title' => '安全审计', 'notice' => $mockNotice]);

    case 'security':
        render('admin/security', ['title' => '安全中心', 'notice' => $mockNotice]);

    case 'ucenter':
        Rbac::gate($admin, 'manage:ucenter');
        $health = null;
        if (Request::input('check') === '1') {
            $health = $uc->healthCheck();
        }
        $calls = Database::getInstance()->query("SELECT * FROM uc_calls ORDER BY id DESC LIMIT 30");
        // UCenter → 应用 通知接收（后台「通知设置」各开关的落地点）
        NotifyHandler::ensureTable();
        $notices = NotifyHandler::recent(30);
        render('admin/ucenter', [
            'title'        => 'UCenter 配置',
            'notice'       => $mockNotice,
            'cfg'          => $uc->getConfig(),
            'ping'         => $uc->ping(),
            'health'       => $health,
            'calls'        => $calls,
            'noticeCfg'    => [
                'enabled'  => intval(Config::get('uc_notify_enabled', 1)),
                'ttl'      => intval(Config::get('uc_notify_ttl', 3600)),
                'strict'   => intval(Config::get('uc_notify_strict', 0)),
                'ips'      => (string)Config::get('uc_notify_ips', ''),
                'keep'     => intval(Config::get('uc_notify_keep', 500)),
                'plain'    => intval(Config::get('uc_notify_plain', 0)),
                'callback' => rtrim(site_url(''), '/') . '/api/uc.php',
            ],
            'noticeActions'=> NotifyHandler::actionMap(),
            'noticeStats'  => NotifyHandler::stats(),
            'notices'      => $notices,
        ]);

    case 'logs':
        Rbac::gate($admin, 'view:logs');
        $type = Request::input('type', 'login');
        $typeMap = [
            'login'    => ['login'],
            'logout'   => ['logout'],
            'register' => ['register'],
            'password' => ['password', 'findpwd'],
            'profile'  => ['profile'],
            'sso'      => ['sso'],
            'api'      => ['api'],
            'ucenter'  => ['ucenter'],
            'admin'    => ['admin'],
            'app'      => ['app'],
        ];
        if ($type === 'all') {
            $list = Logger::search([], intval(Request::input('page', 1)), 25);
        } else {
            $cond = ['type' => ($typeMap[$type] ?? ['login'])[0]];
            if ($type === 'password') {
                // 改密 + 找回密码：单独按多 type 查询
                $db = Database::getInstance();
                $page = max(1, intval(Request::input('page', 1)));
                $off  = ($page - 1) * 25;
                $total = intval($db->val("SELECT COUNT(*) FROM logs WHERE type IN ('password','findpwd')"));
                $rows  = $db->query("SELECT * FROM logs WHERE type IN ('password','findpwd') ORDER BY id DESC LIMIT 25 OFFSET $off");
                foreach ($rows as &$r) { $r['extra'] = json_decode($r['extra'] ?: '[]', true); }
                $list = ['total' => $total, 'rows' => $rows, 'page' => $page, 'size' => 25];
            } else {
                $list = Logger::search($cond, intval(Request::input('page', 1)), 25);
            }
        }
        render('admin/logs', ['title' => '日志中心', 'notice' => $mockNotice, 'list' => $list, 'type' => $type]);

    case 'site':
        Rbac::gate($admin, 'manage:system');
        render('admin/site', ['title' => '站点信息', 'notice' => $mockNotice]);

    case 'deletion':
        Rbac::gate($admin, 'view:users');
        $st = (string)Request::input('status', 'pending');
        render('admin/deletion', [
            'title'   => '账号注销审核',
            'notice'  => $mockNotice,
            'status'  => $st,
            'list'    => DeletionService::listAll($st, intval(Request::input('page', 1)), 20),
            'counts'  => DeletionService::counts(),
            'recycle' => DeletionService::recycle(1, 20),
        ]);

    case 'oauth':
        Rbac::gate($admin, 'manage:system');
        $oaList = [];
        foreach (OAuth::providers() as $k => $p) {
            $oaList[$k] = [
                'meta' => $p,
                'cfg'  => OAuth::config($k),
                'doc'  => OAuth::docs($k),
            ];
        }
        render('admin/oauth', [
            'title'        => '第三方登录',
            'notice'       => $mockNotice,
            'list'         => $oaList,
            'autoRegister' => intval(Config::get('oauth_auto_register', 1)),
        ]);

    case 'settings':
        Rbac::gate($admin, 'manage:system');
        // 分类大纲见 app/Core/SettingsSchema.php：模块 → 分组 → 设置项
        $rows = Database::getInstance()->query("SELECT * FROM settings ORDER BY s_key");
        render('admin/settings', [
            'title'  => '设置中心',
            'notice' => $mockNotice,
            'rows'   => $rows,
            'group'  => (string)Request::input('group', ''),
        ]);

    case 'admins':
        Rbac::gate($admin, 'manage:admins');
        $list = AdminModel::all();
        render('admin/admins', ['title' => '管理员', 'notice' => $mockNotice, 'list' => $list]);

    case 'sdks':
        Rbac::gate($admin, 'manage:system');
        render('admin/sdks', ['title' => 'SDK 包管理', 'notice' => $mockNotice, 'packages' => SdkPackageService::listPackages()]);

    case 'products':
        Rbac::gate($admin, 'manage:system');
        render('admin/products', ['title' => '产品展示', 'notice' => $mockNotice, 'products' => MemberProductModel::all()]);

    default:
        Response::redirect($base . '/admin.php');
}