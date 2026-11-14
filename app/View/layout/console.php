<?php
/**
 * 开发者控制台布局（对标微信开放平台 / QQ 开放平台）
 * 结构：顶部（品牌 / 全局搜索 / 账号区） + 左侧多级导航 + 右侧内容区
 * @param array $v base,title,nav,admin,content,notice,subtitle,ops,crumb
 */
extract($v);
$admin    = $v['admin'] ?? null;
$active   = $v['nav'] ?? 'dashboard';
$base     = $v['base'] ?? '';
$notice   = $v['notice'] ?? '';
$title    = $v['title'] ?? '';
$subtitle = $v['subtitle'] ?? '';
$ops      = $v['ops'] ?? '';
$crumb    = $v['crumb'] ?? null;

/** 导航图标 */
$ICO = [
    'grid'    => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>',
    'user'    => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
    'app'     => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 9h6v6H9z"/>',
    'session' => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/>',
    'shield'  => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
    'log'     => '<path d="M4 4h16v16H4z"/><path d="M8 8h8M8 12h8M8 16h5"/>',
    'lock'    => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
    'link'    => '<path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7L12 5"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7L12 19"/>',
    'cog'     => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/>',
    'admin'   => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.9"/><path d="M16 3.1a4 4 0 0 1 0 7.8"/>',
];
$ico = function ($k) use ($ICO) {
    return '<svg class="cs-nav-ico" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
        . ($ICO[$k] ?? $ICO['grid']) . '</svg>';
};

/**
 * 多级导航：[分组标题, [[key, 标题, 链接参数, 权限点(true=登录即可), 图标], ...]]
 * 权限点沿用 RBAC，未授权项自动隐藏
 */
$navGroups = [
    ['概览', [
        ['dashboard', '控制台', 'dashboard', true, 'grid'],
    ]],
    ['身份与接入', [
        ['users',    '用户管理', 'users',    'view:users',    'user'],
        ['apps',     '应用管理', 'apps',     'view:apps',     'app'],
        ['sessions', '会话管理', 'sessions', 'view:sessions', 'session'],
        ['deletion', '注销审核', 'deletion', 'view:users',    'user'],
    ]],
    ['安全与审计', [
        ['audit',    '安全审计', 'audit',    'view:audit', 'shield'],
        ['logs',     '日志中心', 'logs',     'view:logs',  'log'],
        ['security', '安全中心', 'security', true,         'lock'],
    ]],
    ['系统', [
        ['site',     '站点信息',     'site',     'manage:system',  'cog'],
        ['ucenter',  'UCenter 配置', 'ucenter',  'manage:ucenter', 'link'],
        ['oauth',    '第三方登录',   'oauth',    'manage:system',  'link'],
        ['sdks',     'SDK 包管理',   'sdks',     'manage:system',  'app'],
        ['products', '产品展示',     'products', 'manage:system',  'grid'],
        ['settings', '设置中心',     'settings', 'manage:system',  'cog'],
        ['admins',   '管理员',       'admins',   'manage:admins',  'admin'],
    ]],
];

// 按 RBAC 过滤，并构建全局搜索索引
$groups = [];
$searchIndex = [];
foreach ($navGroups as $g) {
    $items = [];
    foreach ($g[1] as $it) {
        $allow = ($it[3] === true) || ($admin && Rbac::can($admin, $it[3]));
        if (!$allow) {
            continue;
        }
        $items[] = $it;
        $searchIndex[] = [
            'g' => $g[0],
            't' => $it[1],
            'u' => $base . '/admin.php?c=' . $it[2],
        ];
    }
    if ($items) {
        $groups[] = [$g[0], $items];
    }
}

// 面包屑：默认「首页 / 当前页」
if ($crumb === null) {
    $crumb = [['首页', $base . '/admin.php'], [$title ?: '控制台', '']];
}
$roleName = $admin ? ($admin['role'] ?? '') : '';
$showName = $admin ? ($admin['realname'] ?: $admin['username']) : '';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="referrer" content="same-origin">
<title><?= e($title) ?><?= $title ? ' · ' : '' ?><?= e(site_name()) ?> 控制台</title>
<link rel="stylesheet" href="<?= e($base) ?>/assets/app.css?v=20261014-enterprise">
<link rel="stylesheet" href="<?= e($base) ?>/assets/console.css">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%232563eb'/%3E%3Ctext x='16' y='22' font-size='16' text-anchor='middle' fill='%23fff' font-family='sans-serif'%3EU%3C/text%3E%3C/svg%3E">
</head>
<body>
<div class="cs-shell">

  <!-- ==================== 顶部栏 ==================== -->
  <header class="cs-top">
    <button class="cs-burger" id="csBurger" type="button" aria-label="展开导航">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
    </button>

    <a class="cs-top-brand" href="<?= e($base) ?>/admin.php">
      <span class="logo">U</span><?= e(site_name()) ?>
    </a>

    <!-- 全局搜索：检索全部导航目标，回车直达 -->
    <div class="cs-search" id="csSearch">
      <span class="cs-search-ico">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
      </span>
      <input id="csSearchInput" type="search" autocomplete="off" placeholder="搜索菜单与功能（如：用户、应用、日志）">
      <div class="cs-search-pop" id="csSearchPop"></div>
    </div>

    <div class="cs-top-right">
      <a class="cs-top-link" href="<?= e($base) ?>/index.php" target="_blank">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><path d="M15 3h6v6"/><path d="M10 14L21 3"/></svg>
        开放平台
      </a>

      <div class="cs-account">
        <button class="cs-account-btn" id="csAcctBtn" type="button">
          <span class="cs-avatar"><?= e(mb_substr($showName ?: 'A', 0, 1)) ?></span>
          <span class="cs-acct-txt">
            <span class="cs-acct-name"><?= e($showName) ?></span>
            <span class="cs-acct-role"><?= e($roleName) ?></span>
          </span>
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M6 9l6 6 6-6"/></svg>
        </button>
        <div class="cs-menu" id="csAcctMenu">
          <a href="<?= e($base) ?>/admin.php?c=security">安全中心</a>
          <a href="<?= e($base) ?>/admin.php?c=changepwd">修改密码</a>
          <div class="cs-menu-sep"></div>
          <button type="button" onclick="Admin.logout()">退出登录</button>
        </div>
      </div>
    </div>
  </header>

  <!-- ==================== 主体：左侧导航 + 右侧内容 ==================== -->
  <div class="cs-body">
    <div class="cs-mask" id="csMask"></div>

    <aside class="cs-side" id="csSide">
      <nav class="cs-nav">
        <?php foreach ($groups as $g): ?>
          <?php $gKey = 'cs_nav_' . md5($g[0]); ?>
          <div class="cs-nav-group" data-group="<?= e($gKey) ?>">
            <div class="cs-nav-group-h">
              <span><?= e($g[0]) ?></span>
              <span class="cs-caret">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M6 9l6 6 6-6"/></svg>
              </span>
            </div>
            <div class="cs-nav-sub">
              <?php foreach ($g[1] as $it): ?>
                <a class="cs-nav-item <?= $active === $it[0] ? 'on' : '' ?>" href="<?= e($base) ?>/admin.php?c=<?= e($it[2]) ?>">
                  <?= $ico($it[4]) ?>
                  <span><?= e($it[1]) ?></span>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </nav>
    </aside>

    <main class="cs-main">
      <?php if ($notice): ?><div class="alert alert-warn"><?= $notice ?></div><?php endif; ?>

      <div class="cs-page">
        <div>
          <div class="cs-crumb">
            <?php foreach ($crumb as $i => $cb): ?>
              <?php if ($i > 0): ?><span>/</span><?php endif; ?>
              <?php if (!empty($cb[1])): ?>
                <a href="<?= e($cb[1]) ?>"><?= e($cb[0]) ?></a>
              <?php else: ?>
                <span><?= e($cb[0]) ?></span>
              <?php endif; ?>
            <?php endforeach; ?>
          </div>
          <h1 class="cs-page-t"><?= e($title) ?></h1>
          <?php if ($subtitle): ?><div class="cs-page-s"><?= e($subtitle) ?></div><?php endif; ?>
        </div>
        <?php if ($ops): ?><div class="cs-page-ops"><?= $ops ?></div><?php endif; ?>
      </div>

      <?= $content ?>
    </main>
  </div>
</div>

<div class="toast" id="toast"></div>
<script>
var Admin = (function(){
  var API = '<?= e($base) ?>/api/index.php';
  function toast(msg, type){
    var t = document.getElementById('toast');
    t.textContent = msg; t.className = 'toast on ' + (type||'');
    clearTimeout(t._timer);
    t._timer = setTimeout(function(){ t.className = 'toast ' + (type||''); }, 2600);
  }
  function req(m, a, data, opt){
    opt = opt || {};
    data = data || {};
    data.m = m; data.a = a;
    var form = new FormData();
    for (var k in data) form.append(k, data[k]);
    return fetch(API, {
      method: 'POST', body: form, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function(r){ return r.json(); }).then(function(res){
      if (res.code !== 0) {
        if (res.code === 401) { location.href = '<?= e($base) ?>/admin.php?c=login'; return res; }
        toast(res.message || '操作失败', 'err');
      } else if (opt.okMsg !== false && res.message && res.message !== 'ok') {
        toast(res.message, 'ok');
      }
      if (res.code === 0 && opt.after) opt.after(res);
      return res;
    }).catch(function(e){ toast('网络异常：' + e.message, 'err'); });
  }
  function logout(){
    req('admin','logout',{},{okMsg:false}).then(function(){ location.href='<?= e($base) ?>/admin.php?c=login'; });
  }
  function confirmDo(msg, fn){ if (confirm(msg)) fn(); }
  function render(url){ location.href = url; }
  function esc(s){
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(ch){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch];
    });
  }
  return { API: API, req: req, toast: toast, logout: logout, confirmDo: confirmDo, render: render, esc: esc,
           q: function(s){ return document.querySelector(s); } };
})();

/* ---------- 控制台交互：抽屉 / 账号菜单 / 分组折叠 / 全局搜索 ---------- */
(function(){
  var side = document.getElementById('csSide');
  var mask = document.getElementById('csMask');
  var burger = document.getElementById('csBurger');
  function openNav(){ side.classList.add('open'); mask.classList.add('on'); }
  function closeNav(){ side.classList.remove('open'); mask.classList.remove('on'); }
  if (burger) burger.addEventListener('click', function(){
    side.classList.contains('open') ? closeNav() : openNav();
  });
  if (mask) mask.addEventListener('click', closeNav);

  // 账号菜单
  var acctBtn = document.getElementById('csAcctBtn');
  var acctMenu = document.getElementById('csAcctMenu');
  if (acctBtn && acctMenu) {
    acctBtn.addEventListener('click', function(ev){
      ev.stopPropagation();
      acctMenu.classList.toggle('on');
    });
    document.addEventListener('click', function(){ acctMenu.classList.remove('on'); });
    acctMenu.addEventListener('click', function(ev){ ev.stopPropagation(); });
  }

  // 导航分组折叠（状态持久化）
  var KEY = 'uic_cs_nav';
  var collapsed = {};
  try { collapsed = JSON.parse(localStorage.getItem(KEY) || '{}'); } catch (e) { collapsed = {}; }
  var groups = document.querySelectorAll('.cs-nav-group');
  for (var i = 0; i < groups.length; i++) {
    (function(g){
      var k = g.getAttribute('data-group');
      if (collapsed[k]) g.classList.add('collapsed');
      var h = g.querySelector('.cs-nav-group-h');
      if (h) h.addEventListener('click', function(){
        g.classList.toggle('collapsed');
        collapsed[k] = g.classList.contains('collapsed');
        try { localStorage.setItem(KEY, JSON.stringify(collapsed)); } catch (e) {}
      });
    })(groups[i]);
  }

  // 全局搜索
  var NAV = <?= json_encode($searchIndex, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
  var input = document.getElementById('csSearchInput');
  var pop = document.getElementById('csSearchPop');
  if (input && pop) {
    var hits = [];
    function render(list){
      if (!list.length) {
        pop.innerHTML = '<div class="cs-sp-empty">未找到匹配的功能</div>';
      } else {
        var html = '', lastG = '';
        for (var j = 0; j < list.length; j++) {
          var it = list[j];
          if (it.g !== lastG) { html += '<div class="cs-sp-g">' + it.g + '</div>'; lastG = it.g; }
          html += '<a href="' + it.u + '">' + it.t + '</a>';
        }
        pop.innerHTML = html;
      }
      pop.classList.add('on');
    }
    input.addEventListener('focus', function(){ render(hits.length ? hits : NAV.slice(0, 8)); });
    input.addEventListener('input', function(){
      var kw = input.value.trim().toLowerCase();
      if (!kw) { render(NAV.slice(0, 8)); return; }
      hits = NAV.filter(function(x){
        return (x.t + ' ' + x.g).toLowerCase().indexOf(kw) !== -1;
      });
      render(hits);
    });
    input.addEventListener('keydown', function(ev){
      if (ev.key === 'Enter') {
        var first = pop.querySelector('a');
        if (first) location.href = first.getAttribute('href');
      }
      if (ev.key === 'Escape') { pop.classList.remove('on'); input.blur(); }
    });
    document.addEventListener('click', function(ev){
      if (!document.getElementById('csSearch').contains(ev.target)) pop.classList.remove('on');
    });
  }
})();
</script>
</body>
</html>
