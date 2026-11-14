<?php
/**
 * UIC 开放平台 - API 开发文档
 * 统一身份认证中心对外开发者门户
 */
require_once __DIR__ . '/app/bootstrap.php';

// 基础配置
$base    = Config::get('base_url', '') ?: rtrim(site_url(''), '/');
$version = UIC_VERSION;
$active  = 'docs';          // 与首页共用导航：高亮「开放文档」
$user    = $user ?? null;   // index.php 已解析登录态，直接复用

// SDK 文件安全下载（docs.php 经 index.php?c=docs 路由渲染，web 根为 public/）
$dl = $_GET['dl'] ?? '';
if ($dl !== '') {
    $allowList = [
        'composer.json',
        'php/UicSdk.php',
        'js/package.json', 'js/index.js', 'js/dist/uic-sdk.js',
        'python/setup.py', 'python/README.md',
        'go/go.mod', 'go/demo/main.go',
    ];
    if (!in_array($dl, $allowList, true)) {
        http_response_code(404);
        exit('下载文件不存在');
    }
    $abs = __DIR__ . '/sdk/' . $dl;
    if (!is_file($abs)) {
        http_response_code(404);
        exit('下载文件不存在');
    }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($abs) . '"');
    header('Content-Length: ' . filesize($abs));
    readfile($abs);
    exit;
}

// SDK 完整压缩包下载（dlpkg=xxx.zip，白名单来自 data/sdk_packages/index.json，防穿越）
$dlpkg = $_GET['dlpkg'] ?? '';
if ($dlpkg !== '') {
    if (SdkPackageService::download($dlpkg)) {
        exit;
    }
    http_response_code(404);
    exit('下载文件不存在');
}

// 在线 Markdown 开发文档查看器（?doc=xxx，白名单防穿越，仅渲染 docs/ 下指定文档）
$doc = $_GET['doc'] ?? '';
if ($doc !== '') {
    $docMap = [
        'PASSIVE_RISK_ENGINE' => ['file' => 'docs/PASSIVE_RISK_ENGINE.md', 'title' => '无感验证风险引擎'],
        'CAPTCHA_UPGRADE'     => ['file' => 'docs/CAPTCHA_UPGRADE.md',     'title' => '图形验证码升级说明'],
        'MEMBER_PRODUCTS'     => ['file' => 'docs/MEMBER_PRODUCTS.md',     'title' => '会员中心产品展示'],
        'PASSKEY'             => ['file' => 'docs/PASSKEY.md',             'title' => '通行密钥 / WebAuthn'],
        'ADMIN_FINDPWD'       => ['file' => 'docs/ADMIN_FINDPWD.md',       'title' => '管理员找回密码'],
        'SETTINGS_SCHEMA'     => ['file' => 'docs/SETTINGS_SCHEMA.md',     'title' => '设置中心 Schema'],
    ];
    if (!isset($docMap[$doc]) || !is_file(__DIR__ . '/' . $docMap[$doc]['file'])) {
        http_response_code(404);
        exit('文档不存在');
    }
    require_once __DIR__ . '/app/Core/MarkdownLite.php';
    $mdRaw   = file_get_contents(__DIR__ . '/' . $docMap[$doc]['file']);
    $docHtml = uic_md_to_html($mdRaw);
    $docTitle = $docMap[$doc]['title'];
    $docKey   = $doc;
    $base     = Config::get('base_url', '') ?: rtrim(site_url(''), '/');
    $active   = 'docs';
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($docTitle) ?> - 开发文档 - <?= e(site_name()) ?></title>
    <link rel="stylesheet" href="<?= e($base) ?>/assets/app.css?v=20260921-enterprise">
    <style>
      .docview-wrap { max-width:1200px; margin:0 auto; padding:24px; display:flex; gap:24px; }
      .docview-side { width:240px; flex-shrink:0; position:sticky; top:80px; height:calc(100vh - 100px); overflow-y:auto; }
      .docview-side .card { padding:16px; }
      .docview-side a { display:block; padding:8px 12px; color:var(--text); text-decoration:none; border-radius:6px; font-size:14px; }
      .docview-side a:hover { background:var(--bg); color:var(--primary); }
      .docview-side .active { background:var(--primary); color:#fff !important; }
      .docview-side .group-title { font-weight:600; font-size:13px; color:var(--muted); margin:8px 0 8px; padding-left:12px; text-transform:uppercase; letter-spacing:0.5px; }
      .docview-main { flex:1; min-width:0; }
      .docview-main .card { padding:32px 36px; }
      .md-body { line-height:1.75; color:var(--text); }
      .md-body h1 { font-size:28px; font-weight:800; color:var(--primary-d); margin:0 0 20px; padding-bottom:12px; border-bottom:3px solid var(--primary); }
      .md-body h2 { font-size:22px; font-weight:700; color:var(--primary-d); margin:28px 0 14px; padding-bottom:8px; border-bottom:1px solid #e2e8f0; }
      .md-body h3 { font-size:18px; font-weight:600; margin:22px 0 10px; }
      .md-body h4 { font-size:15px; font-weight:600; margin:16px 0 8px; }
      .md-body p { margin:0 0 12px; }
      .md-body code { background:var(--bg); padding:2px 6px; border-radius:4px; font-family:'SF Mono',Monaco,monospace; font-size:13px; color:var(--primary-d); }
      .md-body pre { background:#1e293b; color:#e2e8f0; padding:16px; border-radius:8px; overflow-x:auto; font-family:'SF Mono',Monaco,monospace; font-size:13px; line-height:1.55; }
      .md-body pre code { background:none; padding:0; color:inherit; }
      .md-body .md-table { width:100%; border-collapse:collapse; margin:14px 0; font-size:14px; }
      .md-body .md-table td { padding:9px 12px; border-bottom:1px solid #f1f5f9; }
      .md-body .md-table tr:first-child td { background:var(--bg); font-weight:600; border-bottom:2px solid #e2e8f0; }
      .md-body .md-ul, .md-body .md-ol { margin:0 0 12px; padding-left:24px; }
      .md-body .md-ul li, .md-body .md-ol li { margin:4px 0; }
      .md-body blockquote { background:#eff6ff; border-left:4px solid var(--primary); padding:12px 16px; margin:12px 0; border-radius:0 8px 8px 0; color:var(--text); }
      .md-body hr { border:none; border-top:1px solid #e2e8f0; margin:20px 0; }
      .md-body a { color:var(--primary); }
      @media (max-width:768px){ .docview-wrap{flex-direction:column; padding:12px;} .docview-side{width:auto; position:static; height:auto; margin-bottom:16px;} }
    </style>
    </head>
    <body>
    <?php include APP_PATH . '/View/partials/op_nav.php'; ?>
    <div class="docview-wrap">
      <aside class="docview-side">
        <div class="card">
          <div class="group-title">专题文档</div>
          <?php foreach ($docMap as $k => $d): ?>
          <a href="?c=docs&doc=<?= e($k) ?>" class="<?= $k === $docKey ? 'active' : '' ?>"><?= e($d['title']) ?></a>
          <?php endforeach; ?>
          <div class="group-title" style="margin-top:16px;">返回</div>
          <a href="<?= e($base) ?>/index.php?c=docs">开放文档门户</a>
        </div>
      </aside>
      <main class="docview-main">
        <div class="card">
          <div class="md-body"><?= $docHtml ?></div>
        </div>
      </main>
    </div>
    </body>
    </html>
    <?php
    exit;
}

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>开放文档 - <?= e(site_name()) ?> 开发者平台</title>
<link rel="stylesheet" href="<?= e($base) ?>/assets/app.css?v=20260921-enterprise">
<style>
  /* 文档页面专用样式 */
  .docs-container { max-width:1200px; margin:0 auto; padding:24px; display:flex; gap:24px; }
  .docs-sidebar { width:260px; flex-shrink:0; position:sticky; top:80px; height:calc(100vh - 100px); overflow-y:auto; }
  .docs-sidebar .card { padding:16px; }
  .docs-sidebar a { display:block; padding:8px 12px; color:var(--text); text-decoration:none; border-radius:6px; font-size:14px; }
  .docs-sidebar a:hover { background:var(--bg); color:var(--primary); }
  .docs-sidebar .active { background:var(--primary); color:#fff !important; }
  .docs-sidebar .group-title { font-weight:600; font-size:13px; color:var(--muted); margin:16px 0 8px; padding-left:12px; text-transform:uppercase; letter-spacing:0.5px; }
  .docs-content { flex:1; min-width:0; }
  .docs-content .card { margin-bottom:20px; padding:28px; }
  .docs-content h2 { font-size:24px; font-weight:700; color:var(--primary-d); margin-bottom:16px; padding-bottom:12px; border-bottom:2px solid var(--primary); }
  .docs-content h3 { font-size:18px; font-weight:600; margin:24px 0 12px; color:var(--text); }
  .docs-content h4 { font-size:15px; font-weight:600; margin:16px 0 8px; }
  .docs-content p { line-height:1.7; color:var(--text); margin-bottom:12px; }
  .docs-content code { background:var(--bg); padding:2px 6px; border-radius:4px; font-family:'SF Mono', Monaco, monospace; font-size:13px; color:var(--primary-d); }
  .docs-content pre { background:#1e293b; color:#e2e8f0; padding:16px; border-radius:8px; overflow-x:auto; font-family:'SF Mono', Monaco, monospace; font-size:13px; line-height:1.5; }
  .docs-content pre code { background:none; padding:0; color:inherit; }
  .docs-content .endpoint { background:var(--bg); padding:12px 16px; border-radius:8px; margin:12px 0; font-family:monospace; font-size:14px; border-left:4px solid var(--primary); }
  .docs-content .method { display:inline-block; padding:4px 10px; border-radius:4px; font-weight:600; font-size:12px; margin-right:8px; }
  .method-post { background:#dbeafe; color:#1d4ed8; }
  .method-get { background:#dcfce7; color:#15803d; }
  .param-table { width:100%; border-collapse:collapse; margin:12px 0; font-size:14px; }
  .param-table th { background:var(--bg); padding:10px 12px; text-align:left; font-weight:600; border-bottom:2px solid #e2e8f0; }
  .param-table td { padding:10px 12px; border-bottom:1px solid #f1f5f9; }
  .param-table td:first-child { font-family:monospace; color:var(--primary-d); font-weight:500; }
  .tag { display:inline-block; padding:2px 8px; border-radius:4px; font-size:12px; margin-right:4px; }
  .tag-required { background:#fee2e2; color:#991b1b; }
  .tag-optional { background:#f1f5f9; color:#64748b; }
  .sdk-tabs { display:flex; gap:8px; margin:16px 0; border-bottom:2px solid #e2e8f0; }
  .sdk-tab { padding:8px 16px; cursor:pointer; border-bottom:2px solid transparent; margin-bottom:-2px; font-size:14px; font-weight:500; }
  .sdk-tab.active { border-bottom-color:var(--primary); color:var(--primary); }
  .sdk-panel { display:none; }
  .sdk-panel.active { display:block; }
  .copy-btn { float:right; padding:4px 12px; font-size:12px; cursor:pointer; background:var(--primary); color:#fff; border:none; border-radius:4px; }
  .copy-btn:hover { background:var(--primary-d); }
  .notice { background:#eff6ff; border-left:4px solid var(--primary); padding:12px 16px; margin:12px 0; border-radius:0 8px 8px 0; }
  .notice p { margin:0; font-size:14px; }
  .grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
  .status-card { text-align:center; padding:20px; }
  .status-card h4 { font-size:28px; font-weight:700; color:var(--primary); margin:0; }
  .status-card p { margin:4px 0 0; font-size:13px; color:var(--muted); }
  @media (max-width:768px) {
    .docs-container { flex-direction:column; padding:12px; }
    .docs-sidebar { width:auto; position:static; height:auto; margin-bottom:16px; }
    .grid-2 { grid-template-columns:1fr; }
  }
</style>
</head>
<body>

<!-- 导航栏（与首页共用 op_nav 局部，保证配色、入口、交互完全一致） -->
<?php include APP_PATH . '/View/partials/op_nav.php'; ?>

<div class="docs-container">
  <!-- 侧边栏 -->
  <aside class="docs-sidebar">
    <div class="card">
      <div class="group-title">快速入门</div>
      <a href="#overview" class="active">平台概述</a>
      <a href="#quickstart">快速接入</a>
      <a href="#auth">认证流程</a>
      
      <div class="group-title">API 参考</div>
      <a href="#api-auth">认证接口</a>
      <a href="#api-user">用户接口</a>
      <a href="#api-captcha">验证码接口</a>
      <a href="#api-admin">管理接口</a>
      
      <div class="group-title">SDK 下载</div>
      <a href="#sdk-download">SDK 下载中心</a>
      <a href="#sdk-php">PHP SDK</a>
      <a href="#sdk-js">JavaScript SDK</a>
      <a href="#sdk-python">Python SDK</a>
      <a href="#sdk-go">Go SDK</a>
      
      <div class="group-title">高级功能</div>
      <a href="#advanced">重试 · 批量 · 连接池</a>

      <div class="group-title">专题文档</div>
      <a href="?c=docs&doc=PASSIVE_RISK_ENGINE">无感验证风险引擎</a>
      <a href="?c=docs&doc=CAPTCHA_UPGRADE">图形验证码升级</a>
      <a href="?c=docs&doc=MEMBER_PRODUCTS">会员中心产品展示</a>
      <a href="?c=docs&doc=PASSKEY">通行密钥 / WebAuthn</a>
      <a href="?c=docs&doc=ADMIN_FINDPWD">管理员找回密码</a>
      <a href="?c=docs&doc=SETTINGS_SCHEMA">设置中心 Schema</a>

      <div class="group-title">附录</div>
      <a href="#errors">错误码表</a>
      <a href="#faq">常见问题</a>
      <a href="#changelog">更新日志</a>
    </div>
    
    <div class="card" style="margin-top:16px;">
      <div style="font-size:13px; color:var(--muted);">
        <div style="margin-bottom:8px;"><strong>在线支持</strong></div>
        <div>遇到问题？</div>
        <div style="margin-top:8px;">
          <a href="mailto:dev@uic.local" style="color:var(--primary); font-size:13px;">dev@uic.local</a>
        </div>
      </div>
    </div>
  </aside>

  <!-- 主内容区 -->
  <main class="docs-content">
    
    <!-- 平台概述 -->
    <div class="card" id="overview">
      <h2>平台概述</h2>
      <p><?= e(site_name()) ?> 开放 API 提供标准化的统一身份认证能力，帮助第三方应用快速接入用户体系，实现单点登录、用户管理、安全审计等功能。</p>
      
      <div class="grid-2" style="margin-top:20px;">
        <div class="card status-card">
          <h4>60+</h4>
          <p>开放 API 接口</p>
        </div>
        <div class="card status-card">
          <h4>4</h4>
          <p>语言 SDK 支持</p>
        </div>
        <div class="card status-card">
          <h4>99.9%</h4>
          <p>服务可用性</p>
        </div>
        <div class="card status-card">
          <h4>&lt;100ms</h4>
          <p>平均响应时间</p>
        </div>
      </div>

      <h3>核心能力</h3>
      <table class="param-table">
        <tr><th>能力</th><th>说明</th><th>适用场景</th></tr>
        <tr><td>SSO 单点登录</td><td>OAuth2.0 简化模式，一次登录处处通行</td><td>多应用统一登录态</td></tr>
        <tr><td>用户管理</td><td>注册、登录、改密、资料修改、设备管理</td><td>独立站用户体系</td></tr>
        <tr><td>安全风控</td><td>图形验证码、设备绑定、登录历史审计</td><td>账号安全防护</td></tr>
        <tr><td>应用接入</td><td>AppID / AppSecret 管理，回调白名单</td><td>第三方应用接入</td></tr>
      </table>
    </div>

    <!-- 快速接入 -->
    <div class="card" id="quickstart">
      <h2>快速接入</h2>
      
      <h3>1. 创建应用</h3>
      <p>登录 <a href="<?= e($base) ?>/admin.php" style="color:var(--primary);">管理后台</a>，进入「应用管理」模块，点击「创建应用」获取 <code>AppID</code> 和 <code>AppSecret</code>。</p>
      <div class="notice">
        <p><strong>安全提示：</strong>AppSecret 相当于应用私钥，请勿暴露在前端代码或公开仓库中。生产环境建议使用环境变量或密钥管理服务存储。</p>
      </div>

      <h3>2. 配置回调地址</h3>
      <p>在应用详情页配置授权回调地址（如 <code>http://your-app.local/callback</code>），用户授权后将跳转至此地址并携带 <code>ticket</code> 参数。</p>

      <h3>3. 接入 SDK</h3>
      <p>选择适合您技术栈的 SDK，参照对应语言的快速开始指南接入：</p>
      <div style="display:flex; gap:12px; flex-wrap:wrap; margin-top:12px;">
        <a href="#sdk-php" class="btn btn-s">PHP SDK</a>
        <a href="#sdk-js" class="btn btn-s">JavaScript SDK</a>
        <a href="#sdk-python" class="btn btn-s">Python SDK</a>
        <a href="#sdk-go" class="btn btn-s">Go SDK</a>
      </div>

      <h3>4. 测试连通性</h3>
      <div class="endpoint">
        <span class="method method-post">POST</span>
        <?= e($base) ?>/api/index.php?m=sso&a=test
      </div>
      <p>使用 <code>AppID</code> 和 <code>AppSecret</code> 调用测试接口，验证网络连通与凭证正确性。</p>
    </div>

    <!-- 认证流程 -->
    <div class="card" id="auth">
      <h2>认证流程</h2>
      
      <h3>SSO 单点登录流程</h3>
      <div style="background:var(--bg); padding:20px; border-radius:8px; margin:12px 0;">
        <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
          <div style="text-align:center;">
            <div style="width:60px; height:60px; background:var(--primary); color:#fff; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:24px; font-weight:700;">1</div>
            <div style="font-size:12px; margin-top:8px;">跳转授权页</div>
          </div>
          <div style="font-size:20px; color:var(--primary);">→</div>
          <div style="text-align:center;">
            <div style="width:60px; height:60px; background:var(--primary); color:#fff; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:24px; font-weight:700;">2</div>
            <div style="font-size:12px; margin-top:8px;">用户授权</div>
          </div>
          <div style="font-size:20px; color:var(--primary);">→</div>
          <div style="text-align:center;">
            <div style="width:60px; height:60px; background:var(--primary); color:#fff; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:24px; font-weight:700;">3</div>
            <div style="font-size:12px; margin-top:8px;">回调获取 Ticket</div>
          </div>
          <div style="font-size:20px; color:var(--primary);">→</div>
          <div style="text-align:center;">
            <div style="width:60px; height:60px; background:var(--primary); color:#fff; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:24px; font-weight:700;">4</div>
            <div style="font-size:12px; margin-top:8px;">换取 Token</div>
          </div>
          <div style="font-size:20px; color:var(--primary);">→</div>
          <div style="text-align:center;">
            <div style="width:60px; height:60px; background:var(--primary); color:#fff; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:24px; font-weight:700;">5</div>
            <div style="font-size:12px; margin-top:8px;">获取用户信息</div>
          </div>
        </div>
      </div>

      <h4>Step 1: 构建授权 URL</h4>
      <div class="endpoint">
        <span class="method method-get">GET</span>
        <?= e($base) ?>/index.php?c=sso&a=authorize&app_id={APP_ID}&redirect={CALLBACK}&state={STATE}
      </div>
      <table class="param-table">
        <tr><th>参数</th><th>类型</th><th>必填</th><th>说明</th></tr>
        <tr><td>app_id</td><td>string</td><td><span class="tag tag-required">必填</span></td><td>应用唯一标识</td></tr>
        <tr><td>redirect</td><td>string</td><td><span class="tag tag-required">必填</span></td><td>授权回调地址（需与应用配置一致）</td></tr>
        <tr><td>state</td><td>string</td><td><span class="tag tag-optional">可选</span></td><td>防 CSRF 随机串，回调时原样返回</td></tr>
      </table>

      <h4>Step 2: 换取 Token</h4>
      <div class="endpoint">
        <span class="method method-post">POST</span>
        <?= e($base) ?>/api/index.php?m=sso&a=exchange
      </div>
      <table class="param-table">
        <tr><th>参数</th><th>类型</th><th>必填</th><th>说明</th></tr>
        <tr><td>ticket</td><td>string</td><td><span class="tag tag-required">必填</span></td><td>授权回调获取的一次性票据</td></tr>
        <tr><td>app_id</td><td>string</td><td><span class="tag tag-required">必填</span></td><td>应用 ID</td></tr>
        <tr><td>app_secret</td><td>string</td><td><span class="tag tag-required">必填</span></td><td>应用密钥</td></tr>
      </table>

      <h4>响应示例</h4>
      <pre><code>{
  "code": 0,
  "message": "ok",
  "data": {
    "ok": true,
    "token": "3f2a9c...e17b",
    "expires_in": 604800,
    "uid": 18,
    "username": "zhangsan"
  }
}</code></pre>
      <div class="notice">
        <p>当前版本<strong>不返回 <code>refresh_token</code></strong>；Token 过期后需重新引导用户授权。
        示例中的 <code>expires_in</code> 取后台 <code>sso.token_ttl</code> 配置，默认 604800 秒（7 天）。</p>
      </div>
    </div>

    <!-- API 参考 - 认证接口 -->
    <div class="card" id="api-auth">
      <h2>API 参考 - 认证接口</h2>
      
      <h3>SSO 相关</h3>
      
      <h4>sso.authorize - 发起授权（浏览器跳转）</h4>
      <div class="endpoint">
        <span class="method method-get">GET</span>
        <?= e($base) ?>/index.php?c=sso&amp;a=authorize
      </div>
      <p>由浏览器发起。已登录则 <strong>302 重定向</strong>到回调地址并附带 <code>ticket</code>；未登录返回 401 并在 <code>data</code> 中给出 <code>login_url</code>。</p>
      <table class="param-table">
        <tr><th>参数</th><th>类型</th><th>必填</th><th>说明</th></tr>
        <tr><td>app_id</td><td>string</td><td><span class="tag tag-required">必填</span></td><td>应用 ID</td></tr>
        <tr><td>redirect</td><td>string</td><td><span class="tag tag-optional">可选</span></td><td>回调地址，须在应用回调白名单内；留空取应用首个回调（也接受 <code>callback</code> 作为别名）</td></tr>
      </table>

      <h4>sso.test - 通信测试</h4>
      <div class="endpoint">
        <span class="method method-post">POST</span>
        <?= e($base) ?>/api/index.php?m=sso&amp;a=test
      </div>
      <p>验证应用凭证与网络连通性。</p>
      <table class="param-table">
        <tr><th>参数</th><th>类型</th><th>必填</th><th>说明</th></tr>
        <tr><td>app_id</td><td>string</td><td><span class="tag tag-required">必填</span></td><td>应用 ID</td></tr>
        <tr><td>app_secret</td><td>string</td><td><span class="tag tag-required">必填</span></td><td>应用密钥</td></tr>
      </table>
      <pre><code>// 成功响应
{
  "code": 0,
  "message": "ok",
  "data": {
    "ok": true,
    "message": "通信正常",
    "data": {
      "app_name": "我的应用",
      "status": 1,
      "sso_enabled": 1,
      "callbacks": ["https://app.example.com/callback"],
      "ticket_test": "ok",
      "server_time": 1789878021
    }
  }
}</code></pre>

      <h4>sso.exchange - 换取 Token</h4>
      <div class="endpoint">
        <span class="method method-post">POST</span>
        <?= e($base) ?>/api/index.php?m=sso&amp;a=exchange
      </div>
      <p>用授权回调获取的 <code>ticket</code> 换取访问令牌。<strong>ticket 一次性且有效期很短，换取后立即失效。</strong></p>
      <table class="param-table">
        <tr><th>参数</th><th>类型</th><th>必填</th><th>说明</th></tr>
        <tr><td>ticket</td><td>string</td><td><span class="tag tag-required">必填</span></td><td>授权回调带回的票据</td></tr>
        <tr><td>app_id</td><td>string</td><td><span class="tag tag-required">必填</span></td><td>应用 ID</td></tr>
        <tr><td>app_secret</td><td>string</td><td><span class="tag tag-required">必填</span></td><td>应用密钥</td></tr>
      </table>
      <pre><code>// 成功响应（注意：当前版本不返回 refresh_token）
{
  "code": 0,
  "message": "ok",
  "data": {
    "ok": true,
    "token": "3f2a9c...e17b",
    "expires_in": 604800,
    "uid": 18,
    "username": "zhangsan"
  }
}</code></pre>

      <h4>sso.userinfo - 获取用户信息</h4>
      <div class="endpoint">
        <span class="method method-post">POST</span>
        <?= e($base) ?>/api/index.php?m=sso&amp;a=userinfo
      </div>
      <table class="param-table">
        <tr><th>参数</th><th>类型</th><th>必填</th><th>说明</th></tr>
        <tr><td>token</td><td>string</td><td><span class="tag tag-required">必填</span></td><td>访问令牌；也可通过请求头 <code>Authorization: Bearer &lt;token&gt;</code> 传递</td></tr>
        <tr><td>app_id</td><td>string</td><td><span class="tag tag-optional">可选</span></td><td>应用 ID，传入时校验 token 归属该应用</td></tr>
      </table>
      <pre><code>{
  "code": 0,
  "message": "ok",
  "data": {
    "ok": true,
    "user": {
      "uid": 18,
      "username": "zhangsan",
      "nickname": "张三",
      "avatar": "https://.../avatar.png",
      "email": "z@example.com",
      "mobile": "13800000000",
      "gender": 0,
      "birthday": "",
      "bio": "",
      "status": "normal"
    }
  }
}</code></pre>

      <h4>sso.logout - 单点退出</h4>
      <div class="endpoint">
        <span class="method method-post">POST</span>
        <?= e($base) ?>/api/index.php?m=sso&amp;a=logout
      </div>
      <p>吊销<strong>该 token</strong> 对应的登录态（仅影响当前令牌）。</p>
      <table class="param-table">
        <tr><th>参数</th><th>类型</th><th>必填</th><th>说明</th></tr>
        <tr><td>token</td><td>string</td><td><span class="tag tag-required">必填</span></td><td>访问令牌；也可通过 <code>Authorization: Bearer</code> 传递</td></tr>
        <tr><td>app_id</td><td>string</td><td><span class="tag tag-optional">可选</span></td><td>应用 ID</td></tr>
      </table>
    </div>

    <!-- API 参考 - 用户接口 -->
    <div class="card" id="api-user">
      <h2>API 参考 - 用户接口</h2>
      <p>以下接口需要用户已登录（携带会话 Cookie）或通过 Token 认证。</p>

      <h3>user.register - 用户注册</h3>
      <div class="endpoint">
        <span class="method method-post">POST</span>
        <?= e($base) ?>/api/index.php?m=user&amp;a=register
      </div>
      <table class="param-table">
        <tr><th>参数</th><th>类型</th><th>必填</th><th>说明</th></tr>
        <tr><td>username</td><td>string</td><td><span class="tag tag-required">必填</span></td><td>用户名（3-20位字母数字下划线）</td></tr>
        <tr><td>password</td><td>string</td><td><span class="tag tag-required">必填</span></td><td>密码</td></tr>
        <tr><td>confirm</td><td>string</td><td><span class="tag tag-optional">可选</span></td><td>确认密码；<strong>留空时默认等于 password</strong></td></tr>
        <tr><td>email</td><td>string</td><td><span class="tag tag-optional">可选</span></td><td>邮箱；留空时系统自动生成占位邮箱</td></tr>
        <tr><td>mobile</td><td>string</td><td><span class="tag tag-optional">可选</span></td><td>手机号</td></tr>
        <tr><td>nickname</td><td>string</td><td><span class="tag tag-optional">可选</span></td><td>昵称，留空取用户名</td></tr>
        <tr><td>gender</td><td>int</td><td><span class="tag tag-optional">可选</span></td><td>性别（数值，默认 0）</td></tr>
        <tr><td>auto_login</td><td>string</td><td><span class="tag tag-optional">可选</span></td><td>注册后自动登录（1/0，默认 1）</td></tr>
      </table>
      <pre><code>{
  "code": 0, "message": "注册成功",
  "data": { "ok": true, "uid": 18, "sid": "a1b2c3...", "placeholder_email": false }
}</code></pre>

      <h3>user.login - 用户登录</h3>
      <div class="endpoint">
        <span class="method method-post">POST</span>
        <?= e($base) ?>/api/index.php?m=user&amp;a=login
      </div>
      <table class="param-table">
        <tr><th>参数</th><th>类型</th><th>必填</th><th>说明</th></tr>
        <tr><td>account</td><td>string</td><td><span class="tag tag-required">必填</span></td><td>用户名/邮箱/手机号（也接受 <code>username</code> 作为别名）</td></tr>
        <tr><td>password</td><td>string</td><td><span class="tag tag-required">必填</span></td><td>密码</td></tr>
        <tr><td>remember</td><td>string</td><td><span class="tag tag-optional">可选</span></td><td>记住登录态（1/0，默认 0）</td></tr>
        <tr><td>app_id</td><td>string</td><td><span class="tag tag-optional">可选</span></td><td>SSO 场景下发起授权的应用 ID</td></tr>
        <tr><td>captcha_token</td><td>string</td><td><span class="tag tag-optional">可选</span></td><td>图形验证码票据（后台开启登录验证码时必填）</td></tr>
        <tr><td>captcha_answer</td><td>string</td><td><span class="tag tag-optional">可选</span></td><td>图形验证码答案</td></tr>
      </table>
      <pre><code>{
  "code": 0, "message": "ok",
  "data": { "ok": true, "uid": 18, "username": "zhangsan", "sid": "a1b2c3..." }
}</code></pre>

      <h3>user.info - 获取用户信息</h3>
      <div class="endpoint">
        <span class="method method-post">POST</span>
        <?= e($base) ?>/api/index.php?m=user&amp;a=info
      </div>
      <p>无请求参数，需已登录。</p>
      <pre><code>{
  "code": 0, "message": "ok",
  "data": {
    "uid": 18, "username": "zhangsan", "nickname": "张三",
    "email": "z@example.com", "email_bound": true,
    "mobile": "13800000000", "avatar": "https://.../a.png",
    "gender": 0, "gender_text": "未知", "birthday": "", "bio": "",
    "register_text": "2026-09-20 10:00", "last_login_text": "刚刚",
    "last_login_ip": "127.0.0.1", "status": "normal", "status_text": "正常",
    "source": "UCenter"
  }
}</code></pre>

      <h3>user.security - 账号安全（设备与登录历史）</h3>
      <div class="endpoint">
        <span class="method method-post">POST</span>
        <?= e($base) ?>/api/index.php?m=user&amp;a=security
      </div>
      <p>无请求参数。返回 <code>devices</code>（当前在线设备）与 <code>history</code>（最近 50 条登录/退出记录）。</p>

      <h3>user.logout / user.logoutall - 退出登录</h3>
      <div class="endpoint">
        <span class="method method-post">POST</span>
        <?= e($base) ?>/api/index.php?m=user&amp;a=logout
      </div>
      <div class="endpoint">
        <span class="method method-post">POST</span>
        <?= e($base) ?>/api/index.php?m=user&amp;a=logoutall
      </div>
      <table class="param-table">
        <tr><th>参数</th><th>类型</th><th>必填</th><th>说明</th></tr>
        <tr><td>sid</td><td>string</td><td><span class="tag tag-optional">可选</span></td><td>仅 logout：指定会话，留空退出当前会话</td></tr>
      </table>
      <p><code>logoutall</code> 返回 <code>{ ok: true, revoked: n }</code>，n 为被撤销的会话数。</p>

      <h3>user.device_kill - 踢掉指定设备</h3>
      <div class="endpoint">
        <span class="method method-post">POST</span>
        <?= e($base) ?>/api/index.php?m=user&amp;a=device_kill
      </div>
      <table class="param-table">
        <tr><th>参数</th><th>类型</th><th>必填</th><th>说明</th></tr>
        <tr><td>sid</td><td>string</td><td><span class="tag tag-required">必填</span></td><td>目标会话 ID，必须为当前登录用户自己的会话</td></tr>
      </table>

      <h3>user.check - 账号唯一性检查</h3>
      <div class="endpoint">
        <span class="method method-post">POST</span>
        <?= e($base) ?>/api/index.php?m=user&amp;a=check
      </div>
      <table class="param-table">
        <tr><th>参数</th><th>类型</th><th>必填</th><th>说明</th></tr>
        <tr><td>field</td><td>string</td><td><span class="tag tag-optional">可选</span></td><td>检查字段：username（默认）/ email / mobile</td></tr>
        <tr><td>value</td><td>string</td><td><span class="tag tag-required">必填</span></td><td>待检查的值</td></tr>
      </table>
      <pre><code>{ "code": 0, "message": "ok", "data": { "ok": true, "available": true, "code": 1, "message": "可以注册" } }</code></pre>

      <h3>user.password - 修改密码</h3>
      <div class="endpoint">
        <span class="method method-post">POST</span>
        <?= e($base) ?>/api/index.php?m=user&amp;a=password
      </div>
      <table class="param-table">
        <tr><th>参数</th><th>类型</th><th>必填</th><th>说明</th></tr>
        <tr><td>old_password</td><td>string</td><td><span class="tag tag-required">必填</span></td><td>原密码</td></tr>
        <tr><td>new_password</td><td>string</td><td><span class="tag tag-required">必填</span></td><td>新密码</td></tr>
        <tr><td>confirm</td><td>string</td><td><span class="tag tag-required">必填</span></td><td>确认新密码</td></tr>
      </table>
      <p>成功后返回 <code>{ ok: true, relogin: true }</code>，修改后其他设备会被注销。</p>

      <h3>user.profile - 修改资料</h3>
      <div class="endpoint">
        <span class="method method-post">POST</span>
        <?= e($base) ?>/api/index.php?m=user&amp;a=profile
      </div>
      <table class="param-table">
        <tr><th>参数</th><th>类型</th><th>必填</th><th>说明</th></tr>
        <tr><td>nickname</td><td>string</td><td><span class="tag tag-optional">可选</span></td><td>昵称</td></tr>
        <tr><td>email</td><td>string</td><td><span class="tag tag-optional">可选</span></td><td>邮箱（变更会同步 UCenter）</td></tr>
        <tr><td>mobile</td><td>string</td><td><span class="tag tag-optional">可选</span></td><td>手机号</td></tr>
        <tr><td>gender</td><td>int</td><td><span class="tag tag-optional">可选</span></td><td>性别（数值）</td></tr>
        <tr><td>birthday</td><td>string</td><td><span class="tag tag-optional">可选</span></td><td>生日</td></tr>
        <tr><td>bio</td><td>string</td><td><span class="tag tag-optional">可选</span></td><td>个人简介</td></tr>
        <tr><td>avatar</td><td>string</td><td><span class="tag tag-optional">可选</span></td><td>头像 URL</td></tr>
      </table>

      <h3>user.findpwd_send - 发送找回密码验证码</h3>
      <div class="endpoint">
        <span class="method method-post">POST</span>
        <?= e($base) ?>/api/index.php?m=user&amp;a=findpwd_send
      </div>
      <table class="param-table">
        <tr><th>参数</th><th>类型</th><th>必填</th><th>说明</th></tr>
        <tr><td>target</td><td>string</td><td><span class="tag tag-required">必填</span></td><td>已绑定的邮箱或手机号</td></tr>
        <tr><td>captcha_token</td><td>string</td><td><span class="tag tag-optional">可选</span></td><td>图形验证码票据（开启时必填）</td></tr>
        <tr><td>captcha_answer</td><td>string</td><td><span class="tag tag-optional">可选</span></td><td>图形验证码答案</td></tr>
      </table>
      <p>仅在 <code>debug</code> 开启时响应会附带 <code>debug_code</code> 便于本地联调；生产环境请务必关闭 debug。</p>

      <h3>user.findpwd_reset - 用验证码重置密码</h3>
      <div class="endpoint">
        <span class="method method-post">POST</span>
        <?= e($base) ?>/api/index.php?m=user&amp;a=findpwd_reset
      </div>
      <table class="param-table">
        <tr><th>参数</th><th>类型</th><th>必填</th><th>说明</th></tr>
        <tr><td>target</td><td>string</td><td><span class="tag tag-required">必填</span></td><td>接收验证码的邮箱或手机号</td></tr>
        <tr><td>code</td><td>string</td><td><span class="tag tag-required">必填</span></td><td>收到的验证码</td></tr>
        <tr><td>new_password</td><td>string</td><td><span class="tag tag-required">必填</span></td><td>新密码</td></tr>
        <tr><td>confirm</td><td>string</td><td><span class="tag tag-required">必填</span></td><td>确认新密码</td></tr>
      </table>

      <h3>user.oauth_unbind - 解除第三方绑定</h3>
      <div class="endpoint">
        <span class="method method-post">POST</span>
        <?= e($base) ?>/api/index.php?m=user&amp;a=oauth_unbind
      </div>
      <table class="param-table">
        <tr><th>参数</th><th>类型</th><th>必填</th><th>说明</th></tr>
        <tr><td>provider</td><td>string</td><td><span class="tag tag-required">必填</span></td><td>平台标识：qq / wechat / baidu / alipay / github / google / microsoft / huawei / xiaomi / hbuilder / weibo</td></tr>
      </table>

      <h3>user.deletion_request - 提交账号注销申请</h3>
      <div class="endpoint">
        <span class="method method-post">POST</span>
        <?= e($base) ?>/api/index.php?m=user&amp;a=deletion_request
      </div>
      <table class="param-table">
        <tr><th>参数</th><th>类型</th><th>必填</th><th>说明</th></tr>
        <tr><td>reason</td><td>string</td><td><span class="tag tag-optional">可选</span></td><td>注销原因（最多 500 字）</td></tr>
      </table>
      <p>提交后进入后台审核，审核通过后账号停用并移入回收站。</p>
    </div>

    <!-- API 参考 - 验证码 -->
    <div class="card" id="api-captcha">
      <h2>API 参考 - 验证码接口</h2>

      <p>无需登录即可调用。</p>

      <h3>captcha.issue - 获取验证码</h3>
      <div class="endpoint">
        <span class="method method-post">POST</span>
        <?= e($base) ?>/api/index.php?m=captcha&amp;a=issue
      </div>
      <table class="param-table">
        <tr><th>参数</th><th>类型</th><th>必填</th><th>说明</th></tr>
        <tr><td>style</td><td>string</td><td><span class="tag tag-optional">可选</span></td><td>slide（滑动拼图）/ click（文字点选）/ icon（图标选择）/ passive（无感验证）/ digits（纯数字）/ mixed（数字+字母）；<strong>留空取后台 <code>captcha_style</code> 配置，缺省为 slide</strong></td></tr>
      </table>
      <pre><code>// slide 样式
{
  "code": 0, "message": "ok",
  "data": {
    "token": "8f3c...a91d", "style": "slide",
    "bg": "data:image/png;base64,...",     // 背景图（带缺口）
    "piece": "data:image/png;base64,...",  // 待拖动的拼图块
    "mime": "image/png",
    "size": 50, "width": 300, "height": 150,
    "hole_x": 137, "hole_y": 62            // 缺口左边缘坐标，用于定位拼图块
  }
}

// digits / mixed 样式
{
  "code": 0, "message": "ok",
  "data": { "token": "8f3c...a91d", "style": "digits", "image": "data:image/png;base64,...", "mime": "image/png" }
}</code></pre>
      <div class="notice">
        <p><strong>滑块对接说明：</strong>slider 取值范围为 <code>[0, width]</code>，提交值为拼图块左边缘的横坐标；
        将拼图块定位到 <code>left = 值 / width × 100%</code>、<code>top = hole_y / height × 100%</code> 即可与缺口对齐，
        服务端容差 <code>SLIDE_TOL = 10px</code>。</p>
      </div>

      <h3>captcha.verify - 校验验证码</h3>
      <div class="endpoint">
        <span class="method method-post">POST</span>
        <?= e($base) ?>/api/index.php?m=captcha&amp;a=verify
      </div>
      <table class="param-table">
        <tr><th>参数</th><th>类型</th><th>必填</th><th>说明</th></tr>
        <tr><td>token</td><td>string</td><td><span class="tag tag-required">必填</span></td><td>验证码票据（issue 接口返回）</td></tr>
        <tr><td>answer</td><td>string</td><td><span class="tag tag-required">必填</span></td><td>用户输入的验证码答案（滑块为横坐标数字串）</td></tr>
      </table>
      <div class="notice">
        <p><strong>重要：</strong>校验通过后该 token 会被标记为 <code>used</code>（一次性）。
        因此<strong>不要在登录前先调用本接口预校验</strong>，否则随后的 <code>user.login</code> / <code>admin.login</code>
        会因"验证码已使用"而失败。正确做法是直接把 <code>captcha_token</code> + <code>captcha_answer</code> 随登录请求提交。</p>
      </div>
    </div>

    <!-- API 参考 - 管理接口 -->
    <div class="card" id="api-admin">
      <h2>API 参考 - 管理接口</h2>
      <div class="notice">
        <p><strong>权限要求：</strong>以下接口需要管理员权限，调用时需携带管理员会话。</p>
      </div>

      <div class="notice">
        <p><strong>鉴权：</strong>所有管理接口均需先登录管理后台（携带管理员会话），并具备对应 RBAC 权限点，
        权限不足返回 <code>403</code>。角色矩阵：<code>super_admin</code> 全部；<code>admin</code> 为 manage:users/apps/sessions + 全部 view:*；
        <code>auditor</code> 仅 5 个 view:*（只读）。</p>
      </div>

      <h3>admin.user_list - 用户列表</h3>
      <div class="endpoint">
        <span class="method method-post">POST</span>
        <?= e($base) ?>/api/index.php?m=admin&amp;a=user_list
      </div>
      <p>权限点：<code>view:users</code></p>
      <table class="param-table">
        <tr><th>参数</th><th>类型</th><th>必填</th><th>说明</th></tr>
        <tr><td>kw</td><td>string</td><td><span class="tag tag-optional">可选</span></td><td>搜索关键词（用户名/邮箱/手机号）</td></tr>
        <tr><td>page</td><td>int</td><td><span class="tag tag-optional">可选</span></td><td>页码，默认 1</td></tr>
        <tr><td>size</td><td>int</td><td><span class="tag tag-optional">可选</span></td><td>每页数量，默认 20</td></tr>
      </table>
      <pre><code>{
  "code": 0, "message": "ok",
  "data": {
    "total": 128,
    "rows": [{ "uid": 18, "username": "zhangsan", "email": "z@example.com", "nickname": "张三",
               "mobile": "13800000000", "gender_text": "未知", "sync_status": 1, "uc_ok": 1 }],
    "page": 1, "size": 20
  }
}</code></pre>

      <h3>admin.session_list - 会话列表</h3>
      <div class="endpoint">
        <span class="method method-post">POST</span>
        <?= e($base) ?>/api/index.php?m=admin&amp;a=session_list
      </div>
      <p>权限点：<code>view:sessions</code></p>
      <table class="param-table">
        <tr><th>参数</th><th>类型</th><th>必填</th><th>说明</th></tr>
        <tr><td>kw</td><td>string</td><td><span class="tag tag-optional">可选</span></td><td>搜索关键词</td></tr>
        <tr><td>page</td><td>int</td><td><span class="tag tag-optional">可选</span></td><td>页码，默认 1</td></tr>
        <tr><td>size</td><td>int</td><td><span class="tag tag-optional">可选</span></td><td>每页数量，默认 20，<strong>服务端钳制在 10~50</strong></td></tr>
      </table>

      <h3>admin.log_list - 系统日志</h3>
      <div class="endpoint">
        <span class="method method-post">POST</span>
        <?= e($base) ?>/api/index.php?m=admin&amp;a=log_list
      </div>
      <p>权限点：<code>view:logs</code></p>
      <table class="param-table">
        <tr><th>参数</th><th>类型</th><th>必填</th><th>说明</th></tr>
        <tr><td>type</td><td>string</td><td><span class="tag tag-optional">可选</span></td><td>日志类型：login / logout / register / password / profile / sso / api / ucenter / admin / app</td></tr>
        <tr><td>level</td><td>string</td><td><span class="tag tag-optional">可选</span></td><td>级别：info / warn / error</td></tr>
        <tr><td>uid</td><td>int</td><td><span class="tag tag-optional">可选</span></td><td>按用户 UID 过滤</td></tr>
        <tr><td>app_id</td><td>string</td><td><span class="tag tag-optional">可选</span></td><td>按应用 ID 过滤</td></tr>
        <tr><td>kw</td><td>string</td><td><span class="tag tag-optional">可选</span></td><td>关键词</td></tr>
        <tr><td>page / size</td><td>int</td><td><span class="tag tag-optional">可选</span></td><td>分页，默认 1 / 20</td></tr>
      </table>

      <h3>管理接口速查表（RBAC 权限点）</h3>
      <table class="param-table">
        <tr><th>接口</th><th>权限点</th><th>关键参数</th><th>说明</th></tr>
        <tr><td>admin.stats</td><td>（仅需登录）</td><td>无</td><td>控制台概览统计</td></tr>
        <tr><td>admin.user_action</td><td>manage:users</td><td>uid, act(disable/enable/kick)</td><td>禁用/启用/踢用户</td></tr>
        <tr><td>admin.user_delete</td><td>manage:users</td><td>uid</td><td>永久删除用户</td></tr>
        <tr><td>admin.app_list</td><td>view:apps</td><td>无</td><td>应用列表（<strong>含 app_secret，请妥善保管</strong>）</td></tr>
        <tr><td>admin.app_save</td><td>manage:apps</td><td>id(0=新增), name, description, home_url, callbacks, status, sso_enabled, app_id</td><td>新增/编辑应用</td></tr>
        <tr><td>admin.app_reset_secret</td><td>manage:apps</td><td>id</td><td>重置应用密钥</td></tr>
        <tr><td>admin.app_toggle / app_delete / app_test</td><td>manage:apps / view:apps</td><td>id[, status]</td><td>启停 / 删除 / 连通性测试</td></tr>
        <tr><td>admin.uc_config / uc_save / uc_test / uc_call</td><td>manage:ucenter</td><td>详见后台 UCenter 配置</td><td>UCenter 配置、体检与接口调试</td></tr>
        <tr><td>admin.uc_notify / uc_notify_save / uc_notify_test / uc_notify_clear</td><td>manage:ucenter</td><td>uc_notify_* 系列</td><td>UCenter 通知接收配置</td></tr>
        <tr><td>admin.oauth_save / oauth_save_global</td><td>manage:system</td><td>provider, enabled, appid, secret, private_key / auto_register</td><td>第三方登录配置</td></tr>
        <tr><td>admin.site_save</td><td>manage:system</td><td>site_* 系列（站点名/备案号/联系方式/页脚）</td><td>站点信息设置</td></tr>
        <tr><td>admin.settings / settings_save</td><td>manage:system</td><td>group / data</td><td>系统配置读写</td></tr>
        <tr><td>admin.deletion_list</td><td>view:users</td><td>status(pending/all), page</td><td>注销申请列表</td></tr>
        <tr><td>admin.deletion_review</td><td>view:users</td><td>id, approve(0/1), remark</td><td>注销审核（通过则停用并移入回收站）</td></tr>
        <tr><td>admin.session_kill / session_kick_user</td><td>manage:sessions</td><td>sid / uid</td><td>踢指定会话 / 踢某用户全部会话</td></tr>
        <tr><td>admin.log_clear</td><td>manage:logs</td><td>days(默认 90)</td><td>清理历史日志</td></tr>
        <tr><td>admin.uc_calls</td><td>view:logs</td><td>无</td><td>UCenter 调用记录（最近 50 条）</td></tr>
        <tr><td>admin.audit_stats</td><td>view:audit</td><td>无</td><td>安全审计概览</td></tr>
        <tr><td>admin.admin_list / admin_save / admin_toggle / admin_delete</td><td>manage:admins</td><td>id, username, password, realname, role, status</td><td>管理员管理（内置 id=1 不可改角色/禁用/删除）</td></tr>
        <tr><td>admin.change_pwd / admin_password</td><td>（仅需登录）</td><td>old_password, new_password</td><td>修改当前管理员密码</td></tr>
        <tr><td>admin.totp_status / totp_setup / totp_enable / totp_disable</td><td>（仅需登录）</td><td>code（启用/停用时必填）</td><td>TOTP 二步验证绑定</td></tr>
        <tr><td>admin.login / admin_logout</td><td>无需登录态</td><td>username, password[, captcha_*, totp_code]</td><td>后台登录/退出</td></tr>
      </table>
    </div>

    <!-- SDK 下载中心 -->
    <div class="card" id="sdk-download">
      <h2>SDK 下载中心</h2>
      <p style="margin-bottom:16px">完整 SDK 压缩包由系统后台统一维护，上传后自动同步到本页面，点击即可下载。</p>
      <?php
      $sdkPkgs = SdkPackageService::listPackages();
      if (empty($sdkPkgs)): ?>
        <div class="empty">暂无可下载的 SDK 压缩包</div>
      <?php else: ?>
      <table class="param-table">
        <thead><tr><th>名称</th><th>版本</th><th>简介</th><th>大小</th><th>更新时间</th><th class="right">操作</th></tr></thead>
        <tbody>
        <?php foreach ($sdkPkgs as $p): ?>
          <tr>
            <td><b><?= e($p['title']) ?></b><div class="faint mono" style="font-size:12px"><?= e($p['name']) ?></div></td>
            <td><span class="badge badge-info"><?= e($p['version']) ?></span></td>
            <td style="max-width:280px;font-size:12px"><?= e($p['desc']) ?: '<span class="faint">-</span>' ?></td>
            <td class="mono faint" style="font-size:12px"><?= round(intval($p['size']) / 1048576, 2) ?> MB</td>
            <td class="faint" style="font-size:12px"><?= date('Y-m-d H:i', intval($p['mtime'])) ?></td>
            <td class="right">
              <a class="btn btn-s btn-p" href="<?= e($base) ?>/index.php?c=docs&dlpkg=<?= rawurlencode($p['name']) ?>">下载</a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
      <div class="hint" style="margin-top:12px">需要上传新的 SDK 压缩包？联系站点管理员在「后台 → 系统 → SDK 包管理」中上传。</div>
    </div>

    <!-- SDK - PHP -->
    <div class="card" id="sdk-php">
      <h2>PHP SDK</h2>
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
        <div>
          <span class="badge badge-info">v1.1.0</span>
          <span class="badge badge-ok">稳定版</span>
        </div>
        <div>
          <a href="<?= e($base) ?>/index.php?c=docs&dl=composer.json" class="btn btn-s" download>composer.json</a>
          <a href="<?= e($base) ?>/index.php?c=docs&dl=php/UicSdk.php" class="btn btn-s" download>UicSdk.php</a>
        </div>
      </div>

      <h3>安装</h3>
      <pre><code># 本地集成：将 sdk 目录放入项目，并在 composer.json 中配置：
"repositories": [
    { "type": "path", "url": "./sdk" }
],
"require": { "uic/sdk": "*" }
# 然后执行：composer require uic/sdk</code></pre>

      <h3>快速开始</h3>
      <pre><button class="copy-btn" onclick="copyCode(this)">复制</button><code>&lt;?php
require_once 'vendor/autoload.php';

use UIC\SDK\Client;

$client = new Client([
    'base_url'   => 'http://127.0.0.5',
    'app_id'     => 'your_app_id',
    'app_secret' => 'your_app_secret',
]);

// 获取授权 URL
$authUrl = $client->auth()->getAuthorizeUrl(
    'http://your-app.local/callback',
    bin2hex(random_bytes(16))
);

// 换取 Token（在回调页面执行）
$tokenData = $client->auth()->exchangeToken($_GET['ticket']);

// 获取用户信息
$user = $client->auth()->getUserInfo();
echo "欢迎，" . $user['nickname'];
?&gt;</code></pre>

      <h3>完整功能示例</h3>
      <div class="sdk-tabs">
        <div class="sdk-tab active" onclick="switchTab(this, 'php-auth')">认证</div>
        <div class="sdk-tab" onclick="switchTab(this, 'php-user')">用户</div>
        <div class="sdk-tab" onclick="switchTab(this, 'php-captcha')">验证码</div>
      </div>
      
      <div id="php-auth" class="sdk-panel active">
        <pre><code>// 通信测试
$ok = $client->auth()->testConnect();

// 获取授权 URL
$url = $client->auth()->getAuthorizeUrl($callback, $state);

// 换取 Token
$tokens = $client->auth()->exchangeToken($ticket);

// 获取用户信息
$user = $client->auth()->getUserInfo();

// 单点退出
$client->auth()->logout();</code></pre>
      </div>
      
      <div id="php-user" class="sdk-panel">
        <pre><code>// 用户注册
$user = $client->user()->register([
    'username' => 'newuser',
    'password' => 'Password123',
    'confirm'  => 'Password123',
    'email'    => 'user@example.com',
], true);

// 用户登录
$client->user()->login('username', 'password', true);

// 获取信息
$info = $client->user()->getInfo();

// 修改密码
$client->user()->changePassword('old123', 'new123', 'new123');

// 修改资料
$client->user()->updateProfile(['nickname' => '新昵称']);

// 检查用户名是否可用
$available = $client->user()->checkUnique('username', 'testname');</code></pre>
      </div>
      
      <div id="php-captcha" class="sdk-panel">
        <pre><code>// 获取验证码
$captcha = $client->captcha()->issue('digits');
echo "Token: " . $captcha['token'];

// 校验验证码
$ok = $client->captcha()->verify($captcha['token'], '1234');</code></pre>
      </div>
    </div>

    <!-- SDK - JavaScript -->
    <div class="card" id="sdk-js">
      <h2>JavaScript SDK</h2>
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
        <div>
          <span class="badge badge-info">v1.0.0</span>
          <span class="badge badge-warn">Beta</span>
        </div>
        <div>
          <a href="<?= e($base) ?>/index.php?c=docs&dl=js/package.json" class="btn btn-s" download>package.json</a>
        </div>
      </div>

      <h3>安装</h3>
      <pre><code># 本地使用：将 sdk/js 目录放入项目，或通过 npm link 引入
npm install ./sdk/js
# 或
yarn add ./sdk/js</code></pre>

      <h3>浏览器环境</h3>
      <p><strong>引入方式：</strong>SDK 文件<strong>不在站点公开目录下</strong>，不能直接用 <code>/sdk/js/dist/uic-sdk.js</code> 引用。
      请从下方「SDK 下载」获取 <code>js/dist/uic-sdk.js</code> 后自行托管，再按实际路径引入：</p>
      <pre><code>&lt;!-- 自托管后按实际路径引入 --&gt;
&lt;script src="/your-static/uic-sdk.js"&gt;&lt;/script&gt;
&lt;script&gt;
const client = new UIC.Client({
    baseUrl: '<?= e($base) ?>',
    appId: 'your_app_id',
    appSecret: 'your_app_secret' // 仅限服务端使用
});

// 跳转到授权页（同步返回 URL 字符串）
const url = client.auth.getAuthorizeUrl('http://your-app.local/callback');
window.location.href = url;
&lt;/script&gt;</code></pre>

      <h3>Node.js 环境</h3>
      <pre><code>const { Client } = require('uic-sdk');

const client = new Client({
    baseUrl: '<?= e($base) ?>',
    appId: 'your_app_id',
    appSecret: 'your_app_secret'
});

async function main() {
    // 换取 Token
    const tokens = await client.auth.exchangeToken(req.query.ticket);

    // 获取用户信息
    const user = await client.auth.getUserInfo(tokens.token);
    console.log(user);
}

main();</code></pre>

      <div class="notice">
        <p><strong>安全提示：</strong>浏览器环境中请勿暴露 AppSecret，授权回调处理应在服务端完成。</p>
      </div>
    </div>

    <!-- SDK - Python -->
    <div class="card" id="sdk-python">
      <h2>Python SDK</h2>
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
        <div>
          <span class="badge badge-info">v1.0.0</span>
          <span class="badge badge-warn">Beta</span>
        </div>
        <div>
          <a href="<?= e($base) ?>/index.php?c=docs&dl=python/setup.py" class="btn btn-s" download>setup.py</a>
        </div>
      </div>

      <h3>安装</h3>
      <pre><code>pip install uic-sdk</code></pre>

      <h3>同步调用</h3>
      <pre><code>from uic_sdk import Client

client = Client(
    base_url='http://127.0.0.5',
    app_id='your_app_id',
    app_secret='your_app_secret'
)

# 获取授权 URL
auth_url = client.auth.get_authorize_url(
    callback='http://your-app.local/callback',
    state='random_state_string'
)

# 换取 Token
tokens = client.auth.exchange_ticket(ticket='ticket_from_callback')

# 获取用户信息
user = client.auth.get_user_info(token=tokens['token'])
print(f"欢迎，{user['nickname']}")</code></pre>

      <h3>异步调用（asyncio）</h3>
      <pre><code>import asyncio
from uic_sdk import AsyncClient

async def main():
    client = AsyncClient(
        base_url='<?= e($base) ?>',
        app_id='your_app_id',
        app_secret='your_app_secret'
    )
    try:
        # 换取 Token
        tokens = await client.auth.exchange_ticket('ticket_from_callback')

        # 获取用户信息
        user = await client.auth.get_user_info(token=tokens['token'])
        print(user)
    finally:
        await client.close()  # 关闭底层连接池

asyncio.run(main())</code></pre>

      <h3>Flask 集成示例</h3>
      <pre><code>from flask import Flask, request, redirect
from uic_sdk import Client

app = Flask(__name__)
client = Client(base_url='...', app_id='...', app_secret='...')

@app.route('/login')
def login():
    url = client.auth.get_authorize_url('http://your-app.local/callback')
    return redirect(url)

@app.route('/callback')
def callback():
    ticket = request.args.get('ticket')
    tokens = client.auth.exchange_ticket(ticket)
    session['token'] = tokens['token']
    return '登录成功'</code></pre>
    </div>

    <!-- SDK - Go -->
    <div class="card" id="sdk-go">
      <h2>Go SDK</h2>
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
        <div>
          <span class="badge badge-info">v1.0.0</span>
          <span class="badge badge-warn">Beta</span>
        </div>
        <div>
          <a href="<?= e($base) ?>/index.php?c=docs&dl=go/go.mod" class="btn btn-s" download>go.mod</a>
        </div>
      </div>

      <h3>安装</h3>
      <pre><code># 方式一：本地集成（推荐，SDK 无外部依赖，仅用标准库）
# 将 sdk/go 目录复制到项目，在 go.mod 中引用：
replace github.com/uic/sdk-go =&gt; ./sdk/go

# 方式二：复制源码后直接作为本地包使用（demo 目录即此方式）
go run ./demo</code></pre>

      <h3>快速开始</h3>
      <pre><code>package main

import (
    "context"
    "fmt"
    "log"
    "time"

    uicsdk "github.com/uic/sdk-go"
)

func main() {
    // 初始化客户端（选项式配置：超时 / 日志 / 重试 / context）
    client := uicsdk.NewClient(
        "<?= e($base) ?>",
        uicsdk.WithTimeout(10*time.Second),
        uicsdk.WithRetry(3, 100*time.Millisecond, 2*time.Second),
        uicsdk.WithContext(context.Background()),
    )
    client.SetAppCredentials("your_app_id", "your_app_secret")

    // 获取授权 URL
    authURL := client.AuthorizeURL("your_app_id", "http://your-app.local/callback")
    fmt.Println("授权URL:", authURL)

    // 换取 Token（在服务端回调中执行）
    tokens, err := client.Exchange("ticket_from_callback", "", "")
    if err != nil {
        log.Fatal(err)
    }

    // 获取用户信息
    user, err := client.Userinfo(tokens["token"].(string), "")
    if err != nil {
        log.Fatal(err)
    }
    fmt.Printf("欢迎，%s\n", user["nickname"])
}</code></pre>

      <h3>Gin 框架集成</h3>
      <pre><code>package main

import (
    "net/http"
    "time"

    "github.com/gin-gonic/gin"
    uicsdk "github.com/uic/sdk-go"
)

func main() {
    r := gin.Default()
    client := uicsdk.NewClient(
        "<?= e($base) ?>",
        uicsdk.WithTimeout(10*time.Second),
        uicsdk.WithRetry(3, 100*time.Millisecond, 2*time.Second),
    )
    client.SetAppCredentials("your_app_id", "your_app_secret")

    r.GET("/login", func(c *gin.Context) {
        url := client.AuthorizeURL("your_app_id", "http://your-app.local/callback")
        c.Redirect(http.StatusFound, url)
    })

    r.GET("/callback", func(c *gin.Context) {
        ticket := c.Query("ticket")
        tokens, err := client.Exchange(ticket, "", "")
        if err != nil {
            c.JSON(400, gin.H{"error": err.Error()})
            return
        }
        c.JSON(200, tokens)
    })

    r.Run(":8080")
}</code></pre>
    </div>

    <!-- 错误码 -->
    <div class="card" id="errors">
      <h2>错误码表</h2>
      <div class="notice">
        <p><strong>重要约定：</strong>本平台所有接口的 <strong>HTTP 状态码恒为 200</strong>（含业务错误），
        真正的成功/失败请判断响应体中的 <code>code</code> 字段。<code>code = 0</code> 即成功，非 0 为业务错误。
        请勿用 HTTP 状态码判断业务结果。</p>
      </div>
      <table class="param-table">
        <tr><th>业务码</th><th>含义</th><th>处理建议</th></tr>
        <tr><td>0</td><td>成功</td><td>正常处理 <code>data</code></td></tr>
        <tr><td>1</td><td>通用业务失败</td><td>直接展示 <code>message</code> 给使用者；未显式指定码的错误均返回 1</td></tr>
        <tr><td>400</td><td>请求参数错误 / 业务前置条件不满足</td><td>检查参数与业务状态（如重复申请注销）</td></tr>
        <tr><td>401</td><td>未登录、会话过期或 Token 无效</td><td>引导用户重新登录或重新授权</td></tr>
        <tr><td>403</td><td>权限不足，或被 IP 访问策略拒绝</td><td>检查管理员角色权限点 / 服务器出口 IP 白名单</td></tr>
        <tr><td>404</td><td>接口不存在，或目标资源不存在</td><td>检查 <code>m</code>/<code>a</code> 路径，或账号是否已被 UCenter 删除</td></tr>
        <tr><td>406</td><td>图形验证码错误 / 已失效 / 已使用 / 尝试次数过多</td><td>重新调用 <code>captcha.issue</code> 获取新验证码</td></tr>
        <tr><td>500</td><td>服务端内部错误</td><td>记录 <code>message</code> 并联系技术支持</td></tr>
        <tr><td>501</td><td>UCenter 未实现该接口</td><td>多见于用户删除，需升级 UCenter 服务端</td></tr>
      </table>

      <h4>UCenter / 业务子码（出现在 <code>data.code</code> 或 message 中）</h4>
      <table class="param-table">
        <tr><th>子码</th><th>含义</th><th>处理建议</th></tr>
        <tr><td>-1</td><td>用户名/密码不合法，或用户不存在</td><td>校验输入</td></tr>
        <tr><td>-2</td><td>包含不允许注册的词语，或密码错误</td><td>更换用户名 / 核对密码</td></tr>
        <tr><td>-3</td><td>用户名已被占用</td><td>换一个用户名</td></tr>
        <tr><td>-4</td><td>Email 格式有误</td><td>校验邮箱格式</td></tr>
        <tr><td>-6</td><td>Email 已被注册</td><td>换邮箱或直接登录</td></tr>
        <tr><td>-7</td><td>未做修改 / 接口未实现</td><td>属正常，无需处理</td></tr>
        <tr><td>-8</td><td>该用户受保护，无权限更改</td><td>联系管理员</td></tr>
      </table>

      <h3>通用响应格式</h3>
      <pre><code>// 成功（message 成功时默认为 "ok"）
{
  "code": 0,
  "message": "ok",
  "data": { ... },
  "time": 1789878021
}

// 错误（HTTP 状态码仍为 200）
{
  "code": 401,
  "message": "请先登录",
  "data": null,
  "time": 1789878021
}</code></pre>
    </div>

    <!-- 高级功能 -->
    <div class="card" id="advanced">
      <h2>高级功能：重试 · 批量 · 连接池</h2>
      <p>SDK v1.1.0 起内置生产可用级的容错能力。各语言实现程度如下：</p>
      <table class="param-table">
        <tr><th>能力</th><th>PHP</th><th>JavaScript</th><th>Python</th><th>Go</th></tr>
        <tr><td>指数退避重试</td><td>✔ 可配置</td><td>—</td><td>—</td><td>✔ 可配置</td></tr>
        <tr><td>批量操作</td><td>✔ batchInfo / batchRegister</td><td>—</td><td>—</td><td>—</td></tr>
        <tr><td>连接池 / 会话复用</td><td>—（curl 复用）</td><td>—</td><td>✔（Session / aiohttp 池）</td><td>✔（MaxIdleConns）</td></tr>
        <tr><td>超时控制</td><td>✔</td><td>✔（AbortSignal）</td><td>✔</td><td>✔（WithTimeout / context）</td></tr>
        <tr><td>取消上下文</td><td>—</td><td>—</td><td>—</td><td>✔（WithContext）</td></tr>
      </table>

      <h3>① PHP SDK：配置指数退避重试</h3>
      <pre><code>// 创建客户端时传入重试配置（默认关闭）
$client = new UIC\SDK\Client([
    'base_url'   => '<?= e($base) ?>',
    'app_id'     => 'your_app_id',
    'app_secret' => 'your_app_secret',
    // 重试策略：false 关闭（默认）；数组开启
    'retry' => [
        'max_retries'   => 3,   // 最大重试次数
        'base_delay_ms' => 100, // 初始退避 100ms（指数增长）
        'max_delay_ms'  => 5000,// 最大退避 5s
    ],
]);

// 仅对可重试错误重试：网络错误、HTTP 429/500/502/503/504
// 业务错误码（如 401 参数错误）不会重试，避免无效放大请求量</code></pre>

      <h3>② PHP SDK：批量操作（单条失败不中断）</h3>
      <pre><code>// 批量查询多个 Token 对应的用户信息
$result = $client->user()->batchInfo(
    ['token_a', 'token_b', 'token_c'],
    5 // 并发数，默认 5
);
foreach ($result['items'] as $item) {
    if (!empty($item['_error'])) {
        echo "失败: " . $item['_error'] . "\n";
    } else {
        echo $item['user']['nickname'] . "\n";
    }
}

// 批量注册用户
$result = $client->user()->batchRegister([
    ['username' => 'u1', 'password' => 'Passw0rd!', 'confirm' => 'Passw0rd!'],
    ['username' => 'u2', 'password' => 'Passw0rd!', 'confirm' => 'Passw0rd!'],
]);
echo "成功 {$result['ok']} 条，失败 {$result['fail']} 条";</code></pre>

      <h3>③ Go SDK：重试 + context 取消 + 连接池</h3>
      <pre><code>client := uicsdk.NewClient(
    "<?= e($base) ?>",
    uicsdk.WithTimeout(10*time.Second),
    uicsdk.WithRetry(3, 100*time.Millisecond, 2*time.Second), // 指数退避 + 抖动
    uicsdk.WithContext(ctx),  // 全局取消上下文，传入即生效
)
// 连接池：默认 MaxIdleConns=100、MaxIdleConnsPerHost=10，自动复用 keep-alive 连接</code></pre>

      <h3>④ 调用建议</h3>
      <ul style="line-height:1.9; color:var(--text);">
        <li><strong>重试只用于幂等操作</strong>：查询、开通类接口可安全重试；注册、转账等写操作失败后请核对是否已生效，避免重复提交。</li>
        <li><strong>限流</strong>：服务端当前仅在「登录失败锁定」「短信发送频率」「IP 访问策略」三处做限制，<strong>尚未提供全局 QPS 限流，不会返回 429</strong>；公网部署建议在网关层（Nginx / WAF）自行补充。</li>
        <li><strong>批量操作建议</strong>：单批建议 50 条以内；大量导入请拆批并做好失败记录（<code>_error</code> 字段）。</li>
        <li><strong>连接池注意</strong>：Python 同步客户端使用 <code>requests.Session</code> 复用连接，程序退出前无需特殊处理；异步客户端使用完请 <code>await client.close()</code>。</li>
      </ul>
    </div>

    <!-- FAQ -->
    <div class="card" id="faq">
      <h2>常见问题</h2>
      
      <h4>Q1: 如何获取 AppID 和 AppSecret？</h4>
      <p>登录管理后台，进入「应用管理」模块，点击「创建应用」即可获得。每个应用对应一套独立的凭证。</p>

      <h4>Q2: 回调地址可以配置多个吗？</h4>
      <p>可以。应用支持配置<strong>多个回调地址</strong>（后台「应用管理」中以英文逗号或换行分隔），
      授权时传入的 <code>redirect</code> 必须在白名单内，否则会被拒绝。开发/测试/生产多环境直接配多条即可，无需创建多个应用。
      应用还支持 <code>ip_whitelist</code> 限制回调来源。</p>

      <h4>Q3: Token 有效期多长？如何刷新？</h4>
      <p>Access Token 有效期由后台 <code>sso.token_ttl</code> 配置，<strong>默认 604800 秒（7 天）</strong>。
      当前版本<strong>不支持 Refresh Token</strong>，过期后需重新引导用户授权换取新的 Token。</p>

      <h4>Q4: 支持哪些部署环境？</h4>
      <p>PHP SDK 支持 PHP 7.4+，其他语言 SDK 支持对应语言的主流版本。服务端需开启 ext-json 和 ext-curl 扩展。</p>

      <h4>Q5: 本地开发如何测试？</h4>
      <p>建议在本地 hosts 中配置域名（如 uic.local），或使用 127.0.0.5 作为开发地址。确保回调地址与配置一致。</p>

      <h4>Q6: 生产环境安全注意事项？</h4>
      <p>1) 务必启用 HTTPS；2) AppSecret 存储在服务端环境变量；3) 定期轮换 AppSecret；4) 开启 IP 白名单限制。</p>
    </div>

    <!-- 更新日志 -->
    <div class="card" id="changelog">
      <h2>更新日志</h2>

      <h4>v1.1.0 (2026-09-20)</h4>
      <ul style="line-height:1.8; color:var(--text);">
        <li>PHP SDK：新增指数退避重试机制（<code>config.retry</code> 可配置，默认关闭）</li>
        <li>PHP SDK：新增批量操作 <code>batchInfo()</code> / <code>batchRegister()</code>，单条失败不中断</li>
        <li>Go SDK：新增 <code>WithRetry</code>（重试+抖动）、<code>WithContext</code>（取消控制）、<code>WithTimeout</code></li>
        <li>Go SDK：默认启用 HTTP 连接池（MaxIdleConns=100 / MaxIdleConnsPerHost=10）</li>
        <li>Go SDK：补齐用户接口与验证码接口（register/login/info/password/check/captcha）</li>
        <li>Python SDK：AsyncClient 子客户端（auth/user/captcha）实现真正的异步调用，支持连接池复用</li>
        <li>JavaScript SDK：浏览器 UMD 包 + Node.js CJS 双环境支持</li>
        <li>新增 PHPUnit 单元测试（30 tests / 69 assertions）、JS 冒烟测试、Python pytest 测试</li>
        <li>开放文档页面上线：多语言 SDK 切换、错误码表、快速接入指南、高级功能说明</li>
      </ul>

      <h4>v1.0.0 (2026-09-20)</h4>
      <ul style="line-height:1.8; color:var(--text);">
        <li>初始版本发布</li>
        <li>支持 SSO 单点登录（OAuth2.0 简化模式）</li>
        <li>支持用户注册、登录、改密、资料管理</li>
        <li>支持图形验证码（滑块/数字/混合）</li>
        <li>提供 PHP、JavaScript、Python、Go 四种语言 SDK</li>
      </ul>

      <h4>v1.2.0 (2026-09-21)</h4>
      <ul style="line-height:1.8; color:var(--text);">
        <li><strong>文档与实现对齐</strong>：修正管理接口参数名（<code>user_list</code> 改为 kw/page/size，<code>log_list</code> 改为 type/level/uid/app_id/kw/page/size，<code>session_list</code> size 钳制 10~50）</li>
        <li>修正 <code>sso.exchange</code> 响应示例：移除未实现的 <code>refresh_token</code> 字段</li>
        <li>补齐用户接口文档：<code>security</code> / <code>logout</code> / <code>logoutall</code> / <code>device_kill</code> / <code>check</code> / <code>oauth_unbind</code> / <code>deletion_request</code></li>
        <li>补齐 SSO 文档：<code>authorize</code> 跳转语义、<code>test</code> / <code>userinfo</code> / <code>logout</code> 参数与响应示例</li>
        <li>新增管理接口 RBAC 权限点速查表（30+ 接口）</li>
        <li>错误码表重构：明确「HTTP 恒为 200、业务码在 code 字段」，移除未实现的 429 / 503，补充业务子码 -1~-8</li>
        <li>补充滑块验证码对接说明（<code>hole_x/hole_y</code> 定位、容差 10px）与「校验即作废」注意事项</li>
        <li>修正 FAQ：回调地址支持多个（原描述为仅单个，与实现不符）</li>
      </ul>

      <h4>路线图</h4>
      <ul style="line-height:1.8; color:var(--text);">
        <li>v1.2.0 - Refresh Token 自动刷新机制</li>
        <li>v1.3.0 - WebHook 事件通知</li>
        <li>v1.4.0 - JS/Python SDK 增加重试与批量能力</li>
        <li>v2.0.0 - OAuth2.0 授权码模式</li>
      </ul>
    </div>

  </main>
</div>

<script>
// 代码复制功能
function copyCode(btn) {
    const code = btn.nextElementSibling.innerText;
    navigator.clipboard.writeText(code).then(() => {
        btn.textContent = '已复制';
        setTimeout(() => btn.textContent = '复制', 2000);
    });
}

// SDK Tab 切换
function switchTab(tab, panelId) {
    const container = tab.closest('.card');
    container.querySelectorAll('.sdk-tab').forEach(t => t.classList.remove('active'));
    container.querySelectorAll('.sdk-panel').forEach(p => p.classList.remove('active'));
    tab.classList.add('active');
    document.getElementById(panelId).classList.add('active');
}

// 平滑滚动
function initSmoothScroll() {
    document.querySelectorAll('.docs-sidebar a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            e.preventDefault();
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                document.querySelectorAll('.docs-sidebar a').forEach(a => a.classList.remove('active'));
                this.classList.add('active');
            }
        });
    });
}

// 滚动时高亮侧边栏
function highlightActiveSection() {
    const sections = document.querySelectorAll('.docs-content > .card[id]');
    const sidebarLinks = document.querySelectorAll('.docs-sidebar a[href^="#"]');
    
    let current = '';
    sections.forEach(section => {
        const rect = section.getBoundingClientRect();
        if (rect.top <= 100) {
            current = section.id;
        }
    });
    
    sidebarLinks.forEach(link => {
        link.classList.remove('active');
        if (link.getAttribute('href') === '#' + current) {
            link.classList.add('active');
        }
    });
}

// 初始化
initSmoothScroll();
window.addEventListener('scroll', highlightActiveSection);
highlightActiveSection();
</script>

<!-- 页脚（与首页共用 op_footer 局部） -->
<?php include APP_PATH . '/View/partials/op_footer.php'; ?>

</body>
</html>