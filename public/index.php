<?php
/**
 * 前台门户入口
 * 路由：index.php?c={controller}&a={action}
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

/* 企业级：维护模式（后台不受影响；前台页面统一显示维护公告） */
if (intval(Config::get('maintenance_mode', 0)) === 1) {
    render('portal/maintenance', ['title' => '系统维护中']);
}

$c = preg_replace('/[^a-z_]/', '', strtolower(Request::input('c', 'home')));
$a = preg_replace('/[^a-z_\-]/', '', strtolower(Request::input('a', 'index')));

// docs.php 路由：开发者文档页面
if ($c === 'docs') {
    require_once dirname(__DIR__) . '/docs.php';
    exit;
}

$base = rtrim(site_url(''), '/');
$cur  = UserSession::current();
$user = null;
if ($cur) {
    try {
        $user = UserService::info(intval($cur['uid']));
    } catch (Exception $e) {
        $user = null;
    }
}

function render($tpl, $vars = [])
{
    global $base, $user, $c;
    $vars['base'] = $base;
    $vars['user'] = $user;
    $vars['nav']  = isset($vars['nav']) ? $vars['nav'] : $GLOBALS['c'];
    $vars['content'] = view($tpl, $vars);
    // 认证页（登录/注册/找回密码）使用全屏背景布局，其余页面使用门户布局
    $layout = $vars['layout'] ?? 'layout/portal';
    echo view($layout, $vars);
    exit;
}

switch ($c) {
    // ---------------- 首页 / 用户中心 ----------------
    case 'home':
        // 已登录用户统一进入会员中心（个人资料），旧版「用户中心」页已下线
        if ($user) {
            Response::redirect($base . '/index.php?c=user&a=profile');
        }
        render('portal/home', ['title' => '首页']);

    // ---------------- 登录 ----------------
    case 'user':
        if ($a === 'login') {
            if ($user && !Request::input('redirect')) {
                Response::redirect($base . '/index.php');
            }
            $appId    = Request::input('app_id', '');
            $redirect = Request::input('redirect', '');
            $app = $appId ? AppModel::findByAppId($appId) : null;
            render('portal/login', [
                'title'     => '登录',
                'nav'       => 'login',
                'layout'    => 'layout/portal_auth',
                'app'       => $app,
                'appId'     => $appId,
                'redirect'  => $redirect,
                'oauthList' => OAuth::enabled(),
            ]);
        }
        if ($a === 'register') {
            if (!Config::get('register_enabled', true)) {
                render('portal/notice', ['title' => '注册已关闭', 'msg' => '系统当前未开放注册，请联系管理员。']);
            }
            if ($user) Response::redirect($base . '/index.php');
            render('portal/register', [
                'title'  => '注册账号',
                'nav'    => 'register',
                'layout' => 'layout/portal_auth',
            ]);
        }
        if ($a === 'findpwd') {
            render('portal/findpwd', [
                'title'  => '找回密码',
                'nav'    => 'findpwd',
                'layout' => 'layout/portal_auth',
            ]);
        }
        // profile / security 需登录
        if (!$user) {
            Response::redirect($base . '/index.php?c=user&a=login');
        }
        if ($a === 'profile') {
            render('portal/profile', ['title' => '个人资料', 'nav' => 'profile', 'layout' => 'layout/member']);
        }
        if ($a === 'security') {
            $uid = intval($user['uid']);
            $sec = UserService::security($uid);
            render('portal/security', [
                'title'      => '账号安全',
                'nav'        => 'security',
                'layout'     => 'layout/member',
                'sec'        => $sec,
                'oauthList'  => OAuth::enabled(),
                'oauthBinds' => OAuthService::bindings($uid),
                'deletion'   => DeletionService::status($uid),
                'deletionOn' => DeletionService::enabled(),
            ]);
        }
        Response::redirect($base . '/index.php');

    // ---------------- SSO 授权跳转 ----------------
    case 'sso':
        if ($a === 'authorize') {
            $appId    = Request::input('app_id', '');
            $redirect = Request::input('redirect', '');
            $r = SsoService::authorize($appId, $redirect, []);
            if ($r['ok']) {
                Response::redirect($r['redirect']);
            }
            if (isset($r['need_login'])) {
                $url = $base . '/index.php?c=user&a=login&app_id=' . urlencode($appId) . '&redirect=' . urlencode($redirect);
                Response::redirect($url);
            }
            render('portal/notice', ['title' => '单点登录失败', 'msg' => $r['message']]);
        }
        Response::redirect($base . '/index.php');

    // ---------------- 静态内容页：服务条款 / 隐私政策 / 帮助和反馈 ----------------
    case 'page':
        $pageKey = preg_replace('/[^a-z_]/', '', strtolower($a));
        $page    = SitePages::get($pageKey);
        if (!$page) {
            render('portal/notice', ['title' => '页面不存在', 'msg' => '您访问的页面不存在或已下线。']);
        }
        render('portal/page', [
            'title' => $page['title'],
            'nav'   => $page['nav'],
            'page'  => $page,
        ]);

    default:
        Response::redirect($base . '/index.php');
}
