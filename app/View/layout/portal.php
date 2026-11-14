<?php
/**
 * 开放平台前台布局
 * 与开放文档（docs.php）共用 op_nav / op_footer 两个局部，
 * 保证首页与文档页的配色、导航入口与页脚完全一致。
 * @param array $v base,title,nav,user,content,extraJs
 */
extract($v);
$user      = $v['user'] ?? null;
$navActive = $v['nav'] ?? 'home';
$base      = $v['base'] ?? '';
$active    = $navActive;   // op_nav 读取 $active 判定高亮
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="referrer" content="same-origin">
<title><?= e(($title ?? '') ) ?><?= ($title ?? '') ? ' · ' : '' ?><?= e(site_name()) ?></title>
<link rel="stylesheet" href="<?= e($base) ?>/assets/app.css?v=20260921-enterprise">
<style>.hide{display:none !important}</style>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%232563eb'/%3E%3Ctext x='16' y='22' font-size='16' text-anchor='middle' fill='%23fff' font-family='sans-serif'%3EU%3C/text%3E%3C/svg%3E">
</head>
<body>

<?php include APP_PATH . '/View/partials/op_nav.php'; ?>

<?= $content ?>

<?php include APP_PATH . '/View/partials/op_footer.php'; ?>

<script>
var App = (function(){
  var API = '<?= e($base) ?>/api/index.php';
  var COOKIE = 'uic_sid';
  function toast(msg, type){
    var t = document.getElementById('toast');
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
<?php if (!empty($extraJs)): ?><?= $extraJs ?><?php endif; ?>
</body>
</html>
