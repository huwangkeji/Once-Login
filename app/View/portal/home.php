<?php extract($v);
/** 能力卡图标 */
$ico = function ($p) {
    return '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $p . '</svg>';
};

$capabilities = [
    ['单点登录 SSO', 'OAuth2.0 授权码简化流程，一处登录全站通行，一处退出全站下线。',
     '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="M10 17l5-5-5-5"/><path d="M15 12H3"/>'],
    ['统一账号中心', 'UCenter 作为唯一账号源，UID、用户名、密码、邮箱集中管理，不双写。',
     '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>'],
    ['应用与凭证管理', 'AppID / AppSecret / 回调白名单可视化维护，支持一键重置与通信测试。',
     '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 9h6v6H9z"/>'],
    ['多因子安全防护', '图形验证、TOTP 动态口令、登录失败锁定与 IP 策略，逐层加固。',
     '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>'],
    ['第三方账号登录', 'QQ、微信、GitHub、Google 等 11 种方式可后台按需启用，自动建号绑定。',
     '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a15 15 0 0 1 0 18M12 3a15 15 0 0 0 0 18"/>'],
    ['全链路审计日志', '注册、登录、凭证、管理操作与 UCenter 通信全部留痕，可追溯可导出。',
     '<path d="M4 4h16v16H4z"/><path d="M8 8h8M8 12h8M8 16h5"/>'],
];

$steps = [
    ['创建应用', '在控制台创建应用，获取 AppID 与 AppSecret。'],
    ['配置回调', '填写授权回调地址与白名单，防止越权回跳。'],
    ['集成 SDK', 'PHP / JavaScript / Python / Go 任选，开箱即用。'],
    ['联调上线', '用一键通信测试验证链路，确认无误后发布。'],
];

$resources = [
    ['开放文档', '完整接口说明与接入指南', 'index.php?c=docs', '<path d="M4 4h16v16H4z"/><path d="M8 8h8M8 12h8M8 16h5"/>'],
    ['快速接入', '五分钟跑通第一个登录', 'index.php?c=docs#quickstart', '<path d="M13 2L3 14h8l-1 8 10-12h-8z"/>'],
    ['SDK 下载', '多语言客户端与示例', 'index.php?c=docs#sdk-download', '<path d="M12 3v12M7 10l5 5 5-5"/><path d="M4 20h16"/>'],
    ['错误码表', '接口排障速查', 'index.php?c=docs#errors', '<circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/>'],
    ['管理控制台', '应用、用户、会话与审计', 'admin.php', '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 9h6v6H9z"/>'],
    ['常见问题', '接入高频疑问解答', 'index.php?c=docs#faq', '<circle cx="12" cy="12" r="9"/><path d="M9.1 9a3 3 0 0 1 5.8 1c0 2-3 2.5-3 4"/><path d="M12 17h.01"/>'],
];

// SDK 示例代码（用于首页展示）
$codeExamples = [
    'php' => [
        'label' => 'PHP',
        'code' => "<?php
require_once 'vendor/autoload.php';
use UIC\\SDK\\Client;

// 初始化客户端
\$client = new Client([
    'base_url'   => 'http://127.0.0.5',
    'app_id'     => 'your_app_id',
    'app_secret' => 'your_app_secret',
]);

// 构建授权跳转 URL
\$authUrl = \$client->auth()->getAuthorizeUrl(
    'http://your-app.local/callback',
    bin2hex(random_bytes(16))
);

// 换取 Token（服务端回调中）
\$tokens = \$client->auth()->exchangeToken(\$_GET['ticket']);

// 获取用户信息
\$user = \$client->auth()->getUserInfo(\$tokens['token']);
echo '欢迎，' . \$user['nickname'];",
        'download' => 'index.php?c=docs&dl=composer.json',
        'docs' => 'index.php?c=docs#sdk-php',
    ],
    'js' => [
        'label' => 'JavaScript',
        'code' => "// 浏览器环境
const client = new UIC.Client({
    baseUrl: 'http://127.0.0.5',
    appId: 'your_app_id'
});

// 跳转到授权页（同步返回 URL）
const url = client.auth.getAuthorizeUrl(
    'http://your-app.local/callback'
);
window.location.href = url;

// Node.js 服务端：换取 Token
const tokens = await client.auth.exchangeTicket(req.query.ticket);
const user = await client.auth.getUserInfo(tokens.token);
console.log(user);",
        'download' => 'index.php?c=docs&dl=js/package.json',
        'docs' => 'index.php?c=docs#sdk-js',
    ],
    'python' => [
        'label' => 'Python',
        'code' => "from uic_sdk import Client

# 初始化客户端
client = Client(
    base_url='http://127.0.0.5',
    app_id='your_app_id',
    app_secret='your_app_secret'
)

# 构建授权跳转 URL
auth_url = client.auth.get_authorize_url(
    'http://your-app.local/callback'
)

# 换取 Token（服务端回调中）
tokens = client.auth.exchange_ticket(ticket)

# 获取用户信息
user = client.auth.get_user_info(token=tokens['token'])
print(f\"欢迎，{user['nickname']}\")",
        'download' => 'index.php?c=docs&dl=python/setup.py',
        'docs' => 'index.php?c=docs#sdk-python',
    ],
    'go' => [
        'label' => 'Go',
        'code' => "package main

import (
    \"fmt\"
    \"time\"
    uicsdk \"github.com/uic/sdk-go\"
)

func main() {
    // 初始化客户端
    client := uicsdk.NewClient(
        \"http://127.0.0.5\",
        uicsdk.WithTimeout(10*time.Second),
        uicsdk.WithRetry(3, 100*time.Millisecond, 2*time.Second),
    )
    client.SetAppCredentials(\"your_app_id\", \"your_app_secret\")

    // 获取授权 URL
    authURL := client.AuthorizeURL(\"your_app_id\",
        \"http://your-app.local/callback\")

    // 换取 Token & 获取用户信息
    tokens, _ := client.Exchange(\"ticket\", \"\", \"\")
    user, _ := client.Userinfo(tokens[\"token\"].(string), \"\")
    fmt.Printf(\"欢迎，%s\\n\", user[\"nickname\"])
}",
        'download' => 'index.php?c=docs&dl=go/go.mod',
        'docs' => 'index.php?c=docs#sdk-go',
    ],
];
?>
<div class="op-wrap">
  <?php $v['hero'] = [
      'title'    => site_name(),
      'subtitle' => (string)Config::get('site_slogan', '一次登录，处处通行'),
      'badges'   => ['UCenter 唯一账号源', 'Ticket / Token 双凭证', '多因子认证与风控'],
      'cta'      => true,
  ]; include APP_PATH . '/View/partials/hero_banner.php'; ?>

  <!-- 平台能力 -->
  <section class="op-section">
    <div class="op-section-head">
      <h2 class="op-section-title">平台能力</h2>
      <p class="op-section-sub">面向应用开发者的统一身份基础设施，开箱即用</p>
    </div>
    <div class="op-grid op-grid-3">
      <?php foreach ($capabilities as $c): ?>
      <div class="op-card">
        <div class="op-card-ico"><?= $ico($c[2]) ?></div>
        <div class="op-card-t"><?= e($c[0]) ?></div>
        <div class="op-card-d"><?= e($c[1]) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- 快速接入 -->
  <section class="op-section">
    <div class="op-section-head">
      <h2 class="op-section-title">快速接入</h2>
      <p class="op-section-sub">四步完成对接，多数应用可在半小时内上线</p>
    </div>
    <div class="op-steps">
      <?php foreach ($steps as $i => $s): ?>
      <div class="op-step">
        <div class="op-step-num"><?= intval($i + 1) ?></div>
        <div>
          <div class="op-step-t"><?= e($s[0]) ?></div>
          <div class="op-step-d"><?= e($s[1]) ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="op-cta">
      <a class="btn btn-p" href="<?= e($base) ?>/index.php?c=docs#quickstart">查看接入指南</a>
      <a class="btn" href="<?= e($base) ?>/index.php?c=user&a=register">免费注册开发者账号</a>
    </div>
  </section>

  <!-- 开发者资源 -->
  <section class="op-section">
    <div class="op-section-head">
      <h2 class="op-section-title">开发者资源</h2>
      <p class="op-section-sub">文档、SDK 与排障工具一站式导航</p>
    </div>
    <div class="op-grid op-grid-3">
      <?php foreach ($resources as $r): ?>
      <a class="op-res" href="<?= e($base) ?>/<?= e($r[2]) ?>">
        <span class="op-res-ico"><?= $ico($r[3]) ?></span>
        <span class="op-res-b">
          <span class="op-res-t"><?= e($r[0]) ?></span>
          <span class="op-res-d"><?= e($r[1]) ?></span>
        </span>
        <span class="op-res-arrow">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        </span>
      </a>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- SDK 示例代码 -->
  <section class="op-section">
    <div class="op-section-head">
      <h2 class="op-section-title">SDK 示例代码</h2>
      <p class="op-section-sub">多语言 SDK 开箱即用，复制即可运行</p>
    </div>
    <div class="code-showcase">
      <div class="code-tabs">
        <?php $first = true; foreach ($codeExamples as $key => $ex): ?>
        <button class="code-tab <?= $first ? 'active' : '' ?>" data-tab="<?= e($key) ?>" onclick="switchCodeTab('<?= e($key) ?>')">
          <?= e($ex['label']) ?>
        </button>
        <?php $first = false; endforeach; ?>
      </div>
      <div class="code-panels">
        <?php $first = true; foreach ($codeExamples as $key => $ex): ?>
        <div class="code-panel <?= $first ? 'active' : '' ?>" id="code-panel-<?= e($key) ?>">
          <div class="code-header">
            <span class="code-lang"><?= e($ex['label']) ?></span>
            <div class="code-actions">
              <button class="btn btn-s" onclick="copyCode('<?= e($key) ?>')">复制代码</button>
              <a class="btn btn-s btn-p" href="<?= e($base) ?>/<?= e($ex['download']) ?>">下载 SDK</a>
              <a class="btn btn-s btn-info" href="<?= e($base) ?>/<?= e($ex['docs']) ?>">查看文档</a>
            </div>
          </div>
          <pre class="code-block"><code id="code-<?= e($key) ?>"><?= e($ex['code']) ?></code></pre>
        </div>
        <?php $first = false; endforeach; ?>
      </div>
      <div class="code-footer">
        <a class="btn btn-s" href="<?= e($base) ?>/index.php?c=docs#sdk-download">⬇ 下载完整 SDK 压缩包</a>
        <span class="muted" style="font-size:12px">后台统一维护，上传后自动同步到开放文档下载中心</span>
      </div>
    </div>
  </section>
</div>

<script>
// SDK 示例代码 Tab 切换
function switchCodeTab(tabKey) {
  // 切换按钮高亮
  document.querySelectorAll('.code-tab').forEach(function(btn) {
    btn.classList.toggle('active', btn.dataset.tab === tabKey);
  });
  // 切换面板显示
  document.querySelectorAll('.code-panel').forEach(function(panel) {
    panel.classList.toggle('active', panel.id === 'code-panel-' + tabKey);
  });
}

// 复制代码功能
function copyCode(lang) {
  var codeEl = document.getElementById('code-' + lang);
  if (!codeEl) return;
  var text = codeEl.textContent || codeEl.innerText;
  
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(text).then(function() {
      App.toast('代码已复制到剪贴板', 'ok');
    }).catch(function() {
      fallbackCopy(text);
    });
  } else {
    fallbackCopy(text);
  }
  
  function fallbackCopy(text) {
    var textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.select();
    try {
      document.execCommand('copy');
      App.toast('代码已复制到剪贴板', 'ok');
    } catch (err) {
      App.toast('复制失败，请手动复制', 'err');
    }
    document.body.removeChild(textarea);
  }
}
</script>
