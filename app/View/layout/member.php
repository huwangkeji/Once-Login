<?php
/**
 * 会员中心布局（统一身份认证 · 账号中心）
 *
 * 顶部白色通栏：品牌 + 分组导航（浏览/文档 ｜ 我的账号）+ 右侧用户区与主操作按钮；
 * 窄屏（<=1000px）收起为「汉堡 → 抽屉菜单」（<details> 实现，无 JS 也可展开）。
 * 主体两栏：左侧导航卡（账号导航 / 产品磁贴 / 法律链接）+ 右侧内容卡。
 *
 * 说明：本文件仅调整结构层级与样式，不改变任何鉴权、接口与页面交互逻辑。
 *
 * @param array $v base,title,nav,user,content,extraJs
 */
extract($v);
$user      = $v['user'] ?? null;
$navActive = $v['nav'] ?? 'profile';
$base      = $v['base'] ?? '';
$active    = $navActive;

// 产品列表（懒迁移 + 前缀由 Database 统一注入）
$products = MemberProductModel::listEnabled();

/* 是否同时具有后台登录会话：仅用于决定是否展示「进入控制台」入口，
   直接读会话键、不调用 AdminModel::current()，避免影响后台会话生命周期。 */
$hasAdmin = false;
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    @session_start();
}
$hasAdmin = !empty($_SESSION['uic_admin_id']);

/* 主操作按钮（按当前场景取最有用的一件事；全部复用页面既有能力） */
$cta = null;
if ($hasAdmin) {
    $cta = ['href' => $base . '/admin.php', 'label' => '进入控制台', 'icon' => 'grid'];
} elseif ($user && $active === 'profile') {
    $cta = ['attr' => 'data-edit="nickname"', 'label' => '编辑资料', 'icon' => 'edit'];
} elseif ($user && $active === 'security') {
    $cta = ['attr' => 'data-open="pwdEditor" data-toggle-btn="btnPwdToggle"', 'label' => '修改密码', 'icon' => 'lock'];
}

/* 线性图标集（closure，不污染全局函数命名空间） */
$ico = function ($k) {
    $p = [
        'home'   => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/>',
        'doc'    => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/>',
        'user'   => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        'help'   => '<circle cx="12" cy="12" r="10"/><path d="M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3"/><path d="M12 17h.01"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/>',
        'grid'   => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'edit'   => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
        'lock'   => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'menu'   => '<path d="M3 6h18"/><path d="M3 12h18"/><path d="M3 18h18"/>',
    ];
    if (!isset($p[$k])) {
        return '';
    }
    return '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"'
        . ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p[$k] . '</svg>';
};
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="referrer" content="same-origin">
<title><?= e(($title ?? '')) ?><?= ($title ?? '') ? ' · ' : '' ?><?= e(site_name()) ?></title>
<link rel="stylesheet" href="<?= e($base) ?>/assets/app.css?v=20261015-layout">
<style>.hide{display:none !important}</style>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%232563eb'/%3E%3Ctext x='16' y='22' font-size='16' text-anchor='middle' fill='%23fff' font-family='sans-serif'%3EU%3C/text%3E%3C/svg%3E">
<script>
/* 会员中心全局对象。
   必须放在 <head>：内页脚本（如 passkey.js）会在 body 解析过程中立即调用 App.req，
   若放在 </body> 前，会因 App 尚未定义而报错（通行密钥列表加载不出来）。 */
var App = (function(){
  var API = '<?= e($base) ?>/api/index.php';
  function toast(msg, type){
    var t = document.getElementById('toast');
    if (!t) return;
    t.textContent = msg;
    t.className = 'toast on ' + (type || '');
    clearTimeout(t._timer);
    t._timer = setTimeout(function(){ t.className = 'toast ' + (type||''); }, 2600);
  }
  function req(m, a, data, opt){
    opt = opt || {};
    var form = new FormData();
    form.append('m', m);
    form.append('a', a);
    for (var k in data) { if (data[k] !== null && data[k] !== undefined) form.append(k, data[k]); }
    return fetch(API + '?m=' + encodeURIComponent(m) + '&a=' + encodeURIComponent(a), {
      method: 'POST',
      body: form,
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function(r){ return r.json(); }).then(function(res){
      if (res.code !== 0) {
        if (res.code === 401 && opt.login) { location.href = opt.login; return res; }
        toast(res.message || '操作失败', 'err');
      } else if (opt.okMsg !== false) {
        if (opt.msg || (res.message && res.message !== 'ok')) toast(opt.msg || res.message, 'ok');
      }
      return res;
    }).catch(function(err){
      toast('网络异常：' + err.message, 'err');
      return { code: -1, message: err.message };
    });
  }
  function logout(){
    req('user','logout',{}, {okMsg:false}).then(function(){
      toast('已退出登录','ok');
      setTimeout(function(){ location.href = '<?= e($base) ?>/index.php'; }, 500);
    });
  }
  function submit(formEl, m, a, mapFn, redirect){
    if (typeof formEl === 'string') formEl = document.getElementById(formEl);
    if (!formEl) return;
    formEl.addEventListener('submit', function(ev){
      ev.preventDefault();
      var data = mapFn ? mapFn(new FormData(formEl)) : Object.fromEntries(new FormData(formEl));
      var btn = formEl.querySelector('[type=submit]');
      var oldTxt = btn ? btn.innerHTML : '';
      if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spin"></span>处理中'; }
      req(m, a, data, {okMsg:true}).then(function(res){
        if (btn) { btn.disabled = false; btn.innerHTML = oldTxt; }
        if (res.code === 0 && redirect) setTimeout(function(){ location.href = redirect; }, 600);
      });
    });
  }
  function q(s){ return document.querySelector(s); }
  function qa(s){ return Array.prototype.slice.call(document.querySelectorAll(s)); }
  function val(s){ var el = q(s); return el ? el.value.trim() : ''; }
  return { API: API, req: req, toast: toast, logout: logout, submit: submit, q: q, qa: qa, val: val };
})();
</script>
</head>
<body class="mc-body">

<!-- 顶部白色通栏 -->
<header class="mh">
  <div class="mh-in">
    <a class="mh-brand" href="<?= e($base) ?>/index.php?c=user&amp;a=profile">
      <span class="mh-logo"><?= e(mb_substr(site_name(), 0, 1)) ?></span>
      <span class="mh-name"><?= e(site_name()) ?>账号</span>
    </a>

    <!-- 分组导航（桌面端）：场景一「浏览与文档」 / 场景二「我的账号」 -->
    <nav class="mh-nav" aria-label="主导航">
      <a href="<?= e($base) ?>/index.php"><?= $ico('home') ?>首页</a>
      <a href="<?= e($base) ?>/index.php?c=docs"><?= $ico('doc') ?>开放文档</a>
      <span class="mh-sep" aria-hidden="true"></span>
      <a class="<?= $active === 'profile' ? 'on' : '' ?>" href="<?= e($base) ?>/index.php?c=user&amp;a=profile"><?= $ico('user') ?>个人资料</a>
      <a class="<?= $active === 'security' ? 'on' : '' ?>" href="<?= e($base) ?>/index.php?c=user&amp;a=security"><?= $ico('shield') ?>账号安全</a>
    </nav>

    <div class="mh-right">
      <a class="mh-btn mh-desktop" href="<?= e($base) ?>/index.php?c=page&amp;a=help">
        <?= $ico('help') ?>帮助和反馈
      </a>

      <?php if ($user): ?>
        <a class="mh-user mh-desktop" href="<?= e($base) ?>/index.php?c=user&amp;a=profile" title="个人资料">
          <img src="<?= e($user['avatar']) ?>" alt="">
          <b id="mhNick"><?= e($user['nickname']) ?></b>
        </a>
      <?php endif; ?>

      <?php if ($cta): ?>
        <?php if (!empty($cta['href'])): ?>
          <a class="mh-cta" href="<?= e($cta['href']) ?>"><?= $ico($cta['icon']) ?><?= e($cta['label']) ?></a>
        <?php else: ?>
          <button class="mh-cta" type="button" <?= $cta['attr'] ?>><?= $ico($cta['icon']) ?><?= e($cta['label']) ?></button>
        <?php endif; ?>
      <?php elseif (!$user): ?>
        <a class="mh-cta" href="<?= e($base) ?>/index.php?c=user&amp;a=login"><?= $ico('user') ?>登录</a>
      <?php endif; ?>

      <?php if ($user): ?>
        <button class="mh-btn mh-btn-ic mh-desktop" type="button" title="退出登录" aria-label="退出登录" onclick="App.logout()">
          <?= $ico('logout') ?>
        </button>
      <?php endif; ?>

      <!-- 移动端折叠菜单入口（<details> 原生可折叠） -->
      <details class="mh-drawer" id="mhDrawer">
        <summary class="mh-burger" aria-label="打开菜单"><?= $ico('menu') ?></summary>
        <div class="mh-menu">
          <?php if ($user): ?>
            <div class="mh-menu__user">
              <img src="<?= e($user['avatar']) ?>" alt="">
              <div class="mh-menu__meta">
                <b><?= e($user['nickname']) ?></b>
                <span class="mono">#<?= intval($user['uid']) ?></span>
              </div>
            </div>
          <?php endif; ?>

          <div class="mh-menu__grp">
            <h4>浏览与文档</h4>
            <a href="<?= e($base) ?>/index.php"><?= $ico('home') ?>首页</a>
            <a href="<?= e($base) ?>/index.php?c=docs"><?= $ico('doc') ?>开放文档</a>
          </div>

          <div class="mh-menu__grp">
            <h4>我的账号</h4>
            <a class="<?= $active === 'profile' ? 'on' : '' ?>" href="<?= e($base) ?>/index.php?c=user&amp;a=profile"><?= $ico('user') ?>个人资料</a>
            <a class="<?= $active === 'security' ? 'on' : '' ?>" href="<?= e($base) ?>/index.php?c=user&amp;a=security"><?= $ico('shield') ?>账号安全</a>
            <?php if ($hasAdmin): ?>
              <a href="<?= e($base) ?>/admin.php"><?= $ico('grid') ?>进入控制台</a>
            <?php endif; ?>
          </div>

          <div class="mh-menu__grp">
            <h4>帮助与条款</h4>
            <a href="<?= e($base) ?>/index.php?c=page&amp;a=help"><?= $ico('help') ?>帮助和反馈</a>
            <a href="<?= e($base) ?>/index.php?c=page&amp;a=terms">服务条款</a>
            <a href="<?= e($base) ?>/index.php?c=page&amp;a=privacy">隐私政策</a>
          </div>

          <div class="mh-menu__grp">
            <h4>会话</h4>
            <?php if ($user): ?>
              <button class="mh-menu__act" type="button" onclick="App.logout()"><?= $ico('logout') ?>退出登录</button>
            <?php else: ?>
              <a href="<?= e($base) ?>/index.php?c=user&amp;a=login"><?= $ico('user') ?>登录</a>
            <?php endif; ?>
          </div>
        </div>
      </details>
    </div>
  </div>
</header>

<div class="mc">
  <div class="mc-in">
    <!-- 左侧栏 -->
    <aside class="mc-side">
      <?php if ($user): ?>
        <!-- 移动端用户卡（桌面端隐藏，用户信息在顶栏） -->
        <div class="mc-user">
          <img src="<?= e($user['avatar']) ?>" alt="">
          <div class="mc-user__meta">
            <b><?= e($user['nickname']) ?></b>
            <span class="mono">UID <?= intval($user['uid']) ?></span>
          </div>
        </div>
      <?php endif; ?>

      <nav class="mc-nav" aria-label="账号导航">
        <a class="<?= $active === 'profile' ? 'on' : '' ?>" href="<?= e($base) ?>/index.php?c=user&amp;a=profile">
          <?= $ico('user') ?>个人资料
        </a>
        <a class="<?= $active === 'security' ? 'on' : '' ?>" href="<?= e($base) ?>/index.php?c=user&amp;a=security">
          <?= $ico('shield') ?>账号安全
        </a>
      </nav>

      <div class="mc-products">
        <div class="mc-products__title"><?= e(site_name()) ?>产品</div>
        <?php if (empty($products)): ?>
          <div class="mc-products__empty">暂无展示产品</div>
        <?php else: ?>
        <div class="mc-prod-grid">
          <?php foreach ($products as $p): ?>
            <a class="mc-app" href="<?= e($p['url']) ?>" target="_blank" rel="noopener noreferrer" title="<?= e($p['description'] ?: $p['name']) ?>">
              <span class="mc-app__icon" style="background:<?= e(MemberProductModel::iconColor($p['icon'])) ?>"><?= MemberProductModel::iconSvg($p['icon'], 20) ?></span>
              <span class="mc-app__name"><?= e($p['name']) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>

      <div class="mc-legal">
        <a href="<?= e($base) ?>/index.php?c=page&amp;a=terms">服务条款</a>
        <a href="<?= e($base) ?>/index.php?c=page&amp;a=privacy">隐私政策</a>
        <a href="<?= e($base) ?>/index.php?c=page&amp;a=help">帮助和反馈</a>
      </div>
    </aside>

    <!-- 右侧内容区 -->
    <main class="mc-main">
      <?= $content ?>
    </main>
  </div>
</div>

<div class="toast" id="toast"></div>
<?php if (!empty($extraJs)): ?><?= $extraJs ?><?php endif; ?>

<script>
/* 布局层增强（仅交互润色，不涉及业务逻辑）：
   1) 抽屉菜单：点内部链接自动收起、切到桌面宽度自动收起；
   2) 顶部主操作若指向页面内既有编辑区，则复用页面原有展开逻辑并滚动聚焦。 */
(function(){
  var drawer = document.getElementById('mhDrawer');
  if (drawer) {
    Array.prototype.forEach.call(drawer.querySelectorAll('a'), function(a){
      a.addEventListener('click', function(){ drawer.open = false; });
    });
    if (window.matchMedia) {
      var mq = window.matchMedia('(min-width:1001px)');
      var onMq = function(){ if (mq.matches) drawer.open = false; };
      if (mq.addEventListener) { mq.addEventListener('change', onMq); } else if (mq.addListener) { mq.addListener(onMq); }
    }
  }
  Array.prototype.forEach.call(document.querySelectorAll('[data-open]'), function(btn){
    btn.addEventListener('click', function(){
      var box = document.getElementById(this.getAttribute('data-open'));
      if (!box) return;
      if (box.style.display === 'none') {
        var tg = document.getElementById(this.getAttribute('data-toggle-btn'));
        if (tg) tg.click();
      }
      if (drawer) drawer.open = false;
      setTimeout(function(){
        if (box.scrollIntoView) box.scrollIntoView({ behavior:'smooth', block:'center' });
        var f = box.querySelector('input,textarea,select');
        if (f) f.focus();
      }, 60);
    });
  });
})();
</script>
</body>
</html>
