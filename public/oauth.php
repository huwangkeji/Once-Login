<?php
/**
 * 第三方登录统一入口
 *   /oauth.php?provider=qq&action=login     → 跳转第三方授权页
 *   /oauth.php?provider=qq&action=callback  → 授权回调（在各平台后台填写此地址）
 *   已登录状态下发起 login 会走「绑定已有账号」流程，不会重复建号。
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
SsoModel::gc();

$provider = preg_replace('/[^a-z_]/', '', strtolower((string)Request::input('provider', '')));
$action   = strtolower((string)Request::input('action', 'login'));
$base     = rtrim(site_url(''), '/');
$home     = $base . '/index.php';

/** 轻量提示页（复用 app.css，保持视觉统一） */
function oauth_page($title, $html, $level = 'err')
{
    global $home;
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . e($title) . '</title>'
        . '<link rel="stylesheet" href="' . e($GLOBALS['base']) . '/assets/app.css"></head><body>'
        . '<div class="wrap wrap-narrow" style="padding-top:72px">'
        . '<div class="card"><div class="card-h">' . e($title) . '</div><div class="card-b">'
        . '<div class="alert alert-' . e($level) . '">' . $html . '</div>'
        . '<div class="center" style="margin-top:14px"><a class="btn btn-p" href="' . e($home) . '">返回开放平台</a></div>'
        . '</div></div></body></html>';
    exit;
}

$conf = OAuth::get($provider);
if (!$provider || !$conf) {
    oauth_page('参数错误', '不支持的第三方登录平台，请检查链接。');
}
if (!OAuth::isEnabled($provider)) {
    oauth_page('未启用', '「' . e($conf['name']) . '」登录未启用或尚未在后台配置 AppID / 密钥。');
}
$pname = $conf['name'];

// ==================== 发起授权 ====================
if ($action === 'login' || $action === 'bind') {
    $state = rand_str(24);
    if (!isset($_SESSION['oauth_state']) || !is_array($_SESSION['oauth_state'])) {
        $_SESSION['oauth_state'] = [];
    }
    $_SESSION['oauth_state'][$provider] = $state;
    $_SESSION['oauth_back'] = (string)Request::input('redirect', '') ?: $home;
    Response::redirect(OAuth::authorizeUrl($provider, $state));
}

// ==================== 授权回调 ====================
if ($action === 'callback') {
    $err = (string)Request::input('error', '');
    if ($err !== '') {
        $desc = (string)Request::input('error_description', '');
        oauth_page('授权未完成', '第三方返回错误：<b>' . e($err) . '</b>' . ($desc ? '（' . e($desc) . '）' : '') . '，可能是你取消了授权。');
    }

    $code  = (string)Request::input('code', '');
    $state = (string)Request::input('state', '');
    $saved = (string)($_SESSION['oauth_state'][$provider] ?? '');

    if ($code === '') {
        oauth_page('授权失败', '未获取到授权 code，请重新发起登录。');
    }
    if ($saved !== '' && $state !== $saved) {
        oauth_page('安全校验失败', 'state 校验不一致（可能存在跨站请求伪造风险），已阻止本次登录。请重新发起。');
    }
    unset($_SESSION['oauth_state'][$provider]);

    // 已登录 → 绑定到当前账号；未登录 → 登录 / 自动建号
    $cur     = UserSession::current();
    $bindUid = $cur ? intval($cur['uid']) : 0;

    $r = OAuthService::handleCallback($provider, $code, $bindUid);
    if (empty($r['ok'])) {
        oauth_page($pname . ' 登录失败', e($r['message']));
    }

    if ($bindUid > 0) {
        Response::redirect($base . '/index.php?c=user&a=security&bound=' . urlencode($provider));
    }

    $back = (string)($_SESSION['oauth_back'] ?? '');
    unset($_SESSION['oauth_back']);
    // 只允许回跳本站，防开放重定向
    if ($back === '' || strpos($back, $base) !== 0) {
        $back = $home;
    }
    Response::redirect($back);
}

oauth_page('参数错误', '未知的 action：' . e($action));
