<?php
/**
 * 认证页布局（登录 / 注册 / 找回密码 共用）
 * 全屏星空夜景背景 + 居中白色卡片，视觉对齐参考设计；
 * 页脚固定展示公司主体与「服务条款 / 隐私政策 / 帮助和反馈」导航。
 * 背景为纯 CSS + SVG 绘制（无图片依赖，高分屏不模糊，加载零等待）。
 * @param array $v base,title,content
 */
extract($v);
$base = $v['base'] ?? '';
$authCompany = (string)(Config::get('site_company') ?: '沧州氢境科技有限公司');
$authPages = [
    'terms'   => '服务条款',
    'privacy' => '隐私政策',
    'help'    => '帮助和反馈',
];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="referrer" content="same-origin">
<title><?= e($title ?? '') ?><?= ($title ?? '') ? ' · ' : '' ?><?= e(site_name()) ?></title>
<link rel="stylesheet" href="<?= e($base) ?>/assets/app.css?v=20261014-auth">
<style>.hide{display:none !important}</style>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%232563eb'/%3E%3Ctext x='16' y='22' font-size='16' text-anchor='middle' fill='%23fff' font-family='sans-serif'%3EU%3C/text%3E%3C/svg%3E">
</head>
<body class="auth-body">

<!-- 星空夜景背景：渐变夜空 + 静态远星 + 动态星空画布（逐星闪烁/微漂移/随机流星）
     + 山脉剪影（云雾飘移 / 缓摆 / 山脊微光呼吸 / 鼠标视差），纯 CSS/SVG/Canvas 绘制 -->
<div class="auth-bg" aria-hidden="true">
  <div class="auth-bg__sky"></div>
  <div class="auth-bg__stars"></div>
  <div class="auth-bg__glow"></div>
  <div class="auth-bg__parallax" id="bgParallaxSky">
    <canvas class="auth-bg__canvas" id="authSky"></canvas>
  </div>
  <div class="auth-bg__trail auth-bg__trail--1"></div>
  <div class="auth-bg__trail auth-bg__trail--2"></div>
  <div class="auth-bg__trail auth-bg__trail--3"></div>
  <div class="auth-bg__mount-wrap" id="bgParallaxMount">
    <div class="auth-bg__sway">
      <div class="auth-bg__mist auth-bg__mist--a"></div>
      <div class="auth-bg__mist auth-bg__mist--b"></div>
      <svg class="auth-bg__mountains" viewBox="0 0 1440 320" preserveAspectRatio="xMidYMax slice">
        <path fill="#0d1b2e" d="M0,224 L120,168 L240,208 L360,132 L500,196 L640,120 L780,184 L900,140 L1040,200 L1180,150 L1320,192 L1440,160 L1440,320 L0,320 Z"/>
        <path fill="#091321" d="M0,264 L160,220 L320,252 L480,196 L620,240 L760,204 L920,246 L1080,212 L1240,250 L1440,224 L1440,320 L0,320 Z"/>
      </svg>
    </div>
  </div>
  <div class="auth-bg__vignette"></div>
</div>

<div class="auth-stage">
  <?= $content ?>
</div>

<footer class="auth-foot">
  <div class="auth-foot__copy">© <?= date('Y') ?> <?= e($authCompany) ?></div>
  <nav class="auth-foot__links">
    <?php foreach ($authPages as $ak => $alabel): ?>
      <a href="<?= e($base) ?>/index.php?c=page&amp;a=<?= e($ak) ?>"><?= e($alabel) ?></a>
    <?php endforeach; ?>
  </nav>
</footer>

<div class="toast" id="toast"></div>
<script>
var App = (function(){
  var API = '<?= e($base) ?>/api/index.php';
  function toast(msg, type){
    var t = document.getElementById('toast');
    if (!t) return;
    t.textContent = msg;
    t.className = 'toast on ' + (type || '');
    clearTimeout(t._timer);
    t._timer = setTimeout(function(){ t.className = 'toast ' + (type || ''); }, 2600);
  }
  function req(m, a, data, opt){
    opt = opt || {};
    var form = new FormData();
    form.append('m', m);
    form.append('a', a);
    var d = data || {};
    for (var k in d) { if (d[k] !== null && d[k] !== undefined) form.append(k, d[k]); }
    return fetch(API + '?m=' + encodeURIComponent(m) + '&a=' + encodeURIComponent(a), {
      method: 'POST', body: form, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function(r){ return r.json(); }).then(function(res){
      if (res.code !== 0) {
        if (res.code === 401 && opt.login) { location.href = opt.login; return res; }
        toast(res.message || '操作失败', 'err');
      } else if (opt.okMsg !== false && res.message && res.message !== 'ok') {
        toast(res.message, 'ok');
      }
      return res;
    }).catch(function(err){
      toast('网络异常：' + err.message, 'err');
      return { code: -1, message: err.message };
    });
  }
  function q(s){ return document.querySelector(s); }
  function qa(s){ return Array.prototype.slice.call(document.querySelectorAll(s)); }
  function val(s){ var el = q(s); return el ? el.value.trim() : ''; }
  return { API: API, req: req, toast: toast, q: q, qa: qa, val: val };
})();
</script>
<!-- 动态星空：逐星闪烁 / 随机流星 / 鼠标视差（reduced-motion 与隐藏页签自动降级） -->
<script src="<?= e($base) ?>/assets/auth-bg.js?v=20261014" defer></script>
</body>
</html>
