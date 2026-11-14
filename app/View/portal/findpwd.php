<?php extract($v);
$showCap  = Config::get('login_captcha') ? 1 : 0;
$capStyle = (string)Config::get('captcha_style', 'slide');
$capFallback = (string)Config::get('captcha_fallback', 'slide');
$capApi   = ($base ?? '') . '/api/index.php';
$termsUrl   = ($base ?? '') . '/index.php?c=page&a=terms';
$privacyUrl = ($base ?? '') . '/index.php?c=page&a=privacy';
?>
<div class="auth-card">
  <div class="auth-card__logo" aria-hidden="true">
    <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
  </div>
  <h1 class="auth-card__title">找回密码</h1>

  <!-- 步骤一：验证身份 -->
  <div id="step1">
    <form id="findpwdForm" novalidate>
      <div class="auth-field">
        <input class="auth-input" id="target" placeholder="已绑定的邮箱或手机号" autocomplete="username" required autofocus>
      </div>

      <?php if ($showCap): ?>
      <div class="auth-cap">
        <?php $capShow = 1; include __DIR__ . '/../partials/captcha_card.php'; ?>
      </div>
      <?php endif; ?>

      <div class="auth-field auth-field--row">
        <input class="auth-input" id="code" placeholder="验证码" autocomplete="one-time-code" maxlength="6" inputmode="numeric" required>
        <button class="auth-code-btn" type="button" id="btnSend">获取验证码</button>
      </div>
      <div class="hint" id="sendHint">未配置邮件/短信服务时，验证码会在此提示中回显（调试模式）。</div>

      <div class="auth-agree">
        <label class="auth-check">
          <input type="checkbox" id="agree">
          <span>已阅读并同意<a href="<?= e($termsUrl) ?>" target="_blank" rel="noopener">《服务条款》</a>和<a href="<?= e($privacyUrl) ?>" target="_blank" rel="noopener">《隐私政策》</a></span>
        </label>
      </div>

      <button class="auth-btn" type="button" id="btnNext" disabled>下一步</button>
    </form>
  </div>

  <!-- 步骤二：设置新密码 -->
  <div id="step2" class="hide">
    <form id="resetForm" novalidate>
      <div class="auth-field">
        <input class="auth-input" id="npwd" type="password" placeholder="新密码（6-32 位）" autocomplete="new-password" required>
      </div>
      <div class="auth-field">
        <input class="auth-input" id="npwd2" type="password" placeholder="确认新密码" autocomplete="new-password" required>
      </div>
      <div class="alert alert-info" style="margin:0 0 14px">重置成功后，该账号在所有设备的登录状态都会失效，需要用新密码重新登录。</div>
      <button class="auth-btn" type="button" id="btnReset">确认重置</button>
      <button class="auth-btn auth-btn--ghost" type="button" id="btnBack" style="margin-top:10px">返回上一步</button>
    </form>
  </div>

  <div class="auth-switch">
    想起来了？<a href="<?= e($base) ?>/index.php?c=user&amp;a=login">去登录</a>
  </div>
</div>

<script>
(function(){
  var base = '<?= e($base) ?>';
  var showCap = <?= $showCap ? 'true' : 'false' ?>;
  var agree = document.getElementById('agree');
  var btnNext = document.getElementById('btnNext');

  // 人机验证：统一模块（slide/click/icon/passive/digits/mixed，由后台 captcha_style 决定）
  UicCaptcha.init({
    req: function(){ return App.req.apply(App, arguments); },
    api: '<?= e($capApi) ?>',
    show: showCap,
    style: '<?= e($capStyle) ?>',
    fallback: '<?= e($capFallback) ?>'
  });

  document.querySelectorAll('.auth-agree a').forEach(function(a){
    a.addEventListener('click', function(ev){ ev.stopPropagation(); });
  });

  function refreshBtnState(){
    var filled = App.val('#target') && App.val('#code') && agree.checked
      && (!showCap || UicCaptcha.ready());
    btnNext.disabled = !filled;
  }
  ['#target', '#code'].forEach(function(sel){
    document.querySelector(sel).addEventListener('input', refreshBtnState);
  });
  agree.addEventListener('change', refreshBtnState);
  if (showCap) { setInterval(refreshBtnState, 400); }

  // 获取验证码：60 秒倒计时（与参考交互一致）
  var sending = false, cdTimer = null;
  var btnSend = document.getElementById('btnSend');
  function startCountdown(sec){
    var left = sec;
    btnSend.disabled = true;
    btnSend.textContent = left + 's 后重试';
    cdTimer = setInterval(function(){
      left--;
      if (left <= 0) {
        clearInterval(cdTimer); cdTimer = null;
        btnSend.disabled = false; btnSend.textContent = '获取验证码';
        return;
      }
      btnSend.textContent = left + 's 后重试';
    }, 1000);
  }
  btnSend.addEventListener('click', function(){
    var target = App.val('#target');
    if (!target) { App.toast('请输入邮箱或手机号', 'err'); return; }
    if (sending) return;
    if (showCap && !UicCaptcha.ready()) { App.toast('请完成安全验证', 'err'); return; }
    sending = true;
    var data = { target: target };
    if (showCap) {
      data.captcha_token = UicCaptcha.token;
      data.captcha_answer = UicCaptcha.answer();
    }
    App.req('user','findpwd_send', data, {okMsg:false}).then(function(res){
      sending = false;
      if (res.code !== 0) {
        if (showCap) UicCaptcha.reset();
        refreshBtnState();
        return;
      }
      startCountdown(60);
      var hint = document.getElementById('sendHint');
      if (res.data && res.data.debug_code) {
        hint.innerHTML = '调试模式验证码：<b class="mono">' + res.data.debug_code + '</b>（10 分钟内有效）';
      } else {
        hint.textContent = res.message || '验证码已发送';
      }
    });
  });

  function go(step){
    document.getElementById('step1').classList.toggle('hide', step !== 1);
    document.getElementById('step2').classList.toggle('hide', step !== 2);
  }

  btnNext.addEventListener('click', function(){
    if (!agree.checked) { App.toast('请先阅读并同意服务条款和隐私政策', 'err'); return; }
    if (!App.val('#target') || !App.val('#code')) { App.toast('请填写账号与验证码', 'err'); return; }
    if (showCap && !UicCaptcha.ready()) { App.toast('请完成安全验证', 'err'); return; }
    go(2);
    document.getElementById('npwd').focus();
  });
  document.getElementById('btnBack').addEventListener('click', function(){ go(1); });
  document.getElementById('btnReset').addEventListener('click', function(){
    var d = {
      target: App.val('#target'),
      code: App.val('#code'),
      new_password: App.val('#npwd'),
      confirm: App.val('#npwd2')
    };
    if (!d.new_password) { App.toast('请输入新密码', 'err'); return; }
    if (d.new_password !== d.confirm) { App.toast('两次输入的新密码不一致', 'err'); return; }
    var btn = this; btn.disabled = true; btn.innerHTML = '<span class="spin"></span>提交中';
    App.req('user','findpwd_reset', d, {okMsg:false}).then(function(res){
      btn.disabled = false; btn.innerHTML = '确认重置';
      if (res.code !== 0) { App.toast(res.message, 'err'); return; }
      App.toast('密码已重置，请重新登录', 'ok');
      setTimeout(function(){ location.href = base + '/index.php?c=user&a=login'; }, 900);
    });
  });
})();
</script>
