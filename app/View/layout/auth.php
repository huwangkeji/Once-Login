<?php
/**
 * 后台认证页布局（登录等未登录场景）
 * 不套控制台外壳：居中卡片，品牌与表单由视图自身渲染。
 * 仍提供全局 Admin 对象，保证登录页既有 JS 逻辑零改动。
 * @param array $v base,title,content
 */
extract($v);
$base  = $v['base'] ?? '';
$title = $v['title'] ?? '';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($title) ?><?= $title ? ' · ' : '' ?><?= e(site_name()) ?></title>
<link rel="stylesheet" href="<?= e($base) ?>/assets/app.css">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%232563eb'/%3E%3Ctext x='16' y='22' font-size='16' text-anchor='middle' fill='%23fff' font-family='sans-serif'%3EU%3C/text%3E%3C/svg%3E">
</head>
<body>
<?= $content ?>
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
</script>
</body>
</html>
