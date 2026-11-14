<?php
/**
 * 后台布局
 * @param array $v base,title,nav,admin,content,extraJs,wide
 * 企业级：菜单按 RBAC 角色权限动态过滤
 */
extract($v);
$admin  = $v['admin'] ?? null;
$active = $v['nav'] ?? 'dashboard';
$base   = $v['base'] ?? '';
/** 菜单结构：[key, 标题, 链接参数(含查询串), 权限点(true=登录即可)] */
$allItems = [
    ['dashboard', '控制台', 'dashboard', true],
    ['users',     '用户管理', 'users', 'view:users'],
    ['apps',      '应用管理', 'apps', 'view:apps'],
    ['sessions',  '会话管理', 'sessions', 'view:sessions'],
    ['audit',     '安全审计', 'audit', 'view:audit'],
    ['ucenter',   'UCenter 配置', 'ucenter', 'manage:ucenter'],
    ['logs',      '日志中心', 'logs', 'view:logs'],
    ['apilogs',   'API 日志', 'logs&type=api', 'view:logs'],
    ['uclogs',    'UC 通信日志', 'logs&type=ucenter', 'view:logs'],
    ['settings',  '设置中心', 'settings', 'manage:system'],
    ['admins',    '管理员', 'admins', 'manage:admins'],
];
$items = [];
foreach ($allItems as $it) {
    if ($it[3] === true || ($admin && Rbac::can($admin, $it[3]))) {
        $items[] = $it;
    }
}
$notice = $v['notice'] ?? '';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($title ?? '') ?> · <?= e(site_name()) ?> 后台</title>
<link rel="stylesheet" href="<?= e($base) ?>/assets/app.css?v=20261014-enterprise">
<style>.hide{display:none !important}</style>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%232563eb'/%3E%3Ctext x='16' y='22' font-size='16' text-anchor='middle' fill='%23fff' font-family='sans-serif'%3EU%3C/text%3E%3C/svg%3E">
</head>
<body>

<?php if ($active === 'login'): ?>

  <?= $content ?>

<?php else: ?>

<nav class="nav">
  <div class="nav-in">
    <a class="brand" href="<?= e($base) ?>/admin.php"><span class="logo">U</span><?= e(site_name()) ?><span class="faint" style="font-weight:400"> · 管理后台</span></a>
    <div class="nav-right">
      <?php if ($admin): ?>
        <a class="btn btn-s" href="<?= e($base) ?>/index.php" target="_blank">前台首页</a>
        <a class="btn btn-s" href="<?= e($base) ?>/admin.php?c=security">安全中心</a>
        <span class="faint"><?= e($admin['realname'] ?: $admin['username']) ?> <span class="badge badge-info"><?= e($admin['role']) ?></span></span>
        <a class="btn btn-s" href="javascript:;" onclick="Admin.logout()">退出</a>
      <?php else: ?>
        <a class="btn btn-s" href="<?= e($base) ?>/index.php" target="_blank">前台首页</a>
      <?php endif; ?>
    </div>
  </div>
</nav>

<div class="wrap">
  <div class="side">
    <div class="side-nav">
      <?php foreach ($items as $it): ?>
        <a href="<?= e($base) ?>/admin.php?c=<?= e($it[2]) ?>" class="<?= $active === $it[0] ? 'on' : '' ?>"><?= e($it[1]) ?></a>
      <?php endforeach; ?>
    </div>
    <div>
      <?php if ($notice): ?><div class="alert alert-warn"><?= e($notice) ?></div><?php endif; ?>
      <?= $content ?>
    </div>
  </div>
</div>

<div class="footer"><?= e(site_name()) ?> v<?= UIC_VERSION ?></div>
<div class="toast" id="toast"></div>

<?php endif; ?>

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
  function confirmDo(msg, fn){
    if (confirm(msg)) fn();
  }
  function render(url){
    location.href = url;
  }
  function esc(s){
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(ch){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch];
    });
  }
  return { API: API, req: req, toast: toast, logout: logout, confirmDo: confirmDo, render: render, esc: esc, q:function(s){return document.querySelector(s)} };
})();
</script>
<?php if (!empty($extraJs)): ?><?= $extraJs ?><?php endif; ?>
</body>
</html>