<?php extract($v);
$app         = $v['app'] ?? null;
$appId       = $v['appId'] ?? '';
$redirect    = $v['redirect'] ?? '';
$oauthList   = $v['oauthList'] ?? [];
$showCap     = Config::get('login_captcha') ? 1 : 0;
$capStyle    = (string)Config::get('captcha_style', 'slide');
$capFallback = (string)Config::get('captcha_fallback', 'slide');
$capApi      = ($base ?? '') . '/api/index.php';
$termsUrl    = ($base ?? '') . '/index.php?c=page&a=terms';
$privacyUrl  = ($base ?? '') . '/index.php?c=page&a=privacy';
$passkeyOn   = (int) Config::get('passkey_enabled', 0) === 1;
// WebAuthn 只在「合法域名 + 安全上下文」下可用；IP 访问时直接隐藏入口，
// 避免用户点了才被浏览器抛 "This is an invalid domain."
$passkeyUsable = $passkeyOn && PasskeyService::domainUsable();
$loginCodeOn    = (int) Config::get('login_code_enabled', 1) === 1;
$loginCodeEmail = (int) Config::get('login_code_email_enabled', 1) === 1;
$loginCodeSms   = (int) Config::get('login_code_sms_enabled', 0) === 1;
// 验证码登录可用通道：至少有一个通道开启
$loginCodeAvail = $loginCodeOn && ($loginCodeEmail || $loginCodeSms);
// 验证码登录的账号框 placeholder
$codePlaceholder = $loginCodeEmail && $loginCodeSms ? '邮箱 / 手机号'
                 : ($loginCodeSms ? '手机号' : '邮箱');
?>
<div class="auth-card">
  <div class="auth-card__logo" aria-hidden="true">
    <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>
  </div>
  <h1 class="auth-card__title"><?= e(site_name()) ?></h1>

  <?php if ($app): ?>
    <div class="auth-card__notice">正在从 <b><?= e($app['name']) ?></b> 跳转登录，登录成功后会自动返回该站点。</div>
  <?php endif; ?>

  <?php if ($loginCodeAvail): ?>
  <div class="auth-tabs">
    <button type="button" class="auth-tab on" id="tab-pwd">密码登录</button>
    <button type="button" class="auth-tab" id="tab-code">验证码登录</button>
  </div>
  <?php endif; ?>

  <form id="loginForm" novalidate>
    <div class="auth-field">
      <input class="auth-input" id="account" name="account" placeholder="手机号 / 用户名 / 邮箱"
             autocomplete="<?= $passkeyUsable ? 'username webauthn' : 'username' ?>" required autofocus>
    </div>
    <div class="auth-field">
      <input class="auth-input" id="password" name="password" type="password" placeholder="密码"
             autocomplete="current-password" required>
      <!-- 忘记密码：位于密码框下方右对齐；随所属 .auth-field 一并被 switchMode() 隐藏（验证码登录模式不显示） -->
      <div class="auth-forgot">
        <a href="<?= e($base) ?>/index.php?c=user&amp;a=findpwd">忘记密码？</a>
      </div>
    </div>
    <?php if ($loginCodeAvail): ?>
    <div class="auth-field auth-field--row" id="field-code" style="display:none;">
      <input class="auth-input" id="loginCode" name="login_code" type="text" inputmode="numeric"
             placeholder="验证码" autocomplete="one-time-code" maxlength="6" style="flex:1;min-width:0;">
      <button type="button" class="auth-code-btn" id="sendCodeBtn">获取验证码</button>
    </div>
    <?php endif; ?>

    <?php if ($showCap): ?>
    <!-- 安全验证（紧凑内嵌模式，由 assets/captcha.js 驱动） -->
    <div class="auth-cap">
      <?php $capShow = 1; include __DIR__ . '/../partials/captcha_card.php'; ?>
    </div>
    <?php endif; ?>

    <div class="auth-agree">
      <label class="auth-check">
        <input type="checkbox" id="agree">
        <span>已阅读并同意<a href="<?= e($termsUrl) ?>" target="_blank" rel="noopener">《服务条款》</a>和<a href="<?= e($privacyUrl) ?>" target="_blank" rel="noopener">《隐私政策》</a></span>
      </label>
    </div>

    <div class="auth-opts">
      <label class="auth-check auth-check--sm"><input type="checkbox" id="remember" checked> 记住登录状态</label>
      <label class="auth-check auth-check--sm"><input type="checkbox" id="kickAll"> 挤下线其他设备</label>
    </div>

    <button class="auth-btn" type="submit" id="loginBtn" disabled>登 录</button>
    <?php if ($passkeyUsable): ?>
    <button class="auth-btn auth-btn--passkey" type="button" id="passkeyBtn" style="margin-top:10px;display:none;">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-3px;margin-right:6px;"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
      通行密钥登录
    </button>
    <?php endif; ?>
  </form>

  <div class="auth-switch">
    <span class="on">登录</span><span class="auth-switch__sep">/</span><a href="<?= e($base) ?>/index.php?c=user&amp;a=register">注册</a>
  </div>

  <?php if (!empty($oauthList)): ?>
  <div class="auth-alt">
    <div class="auth-alt__title">其他登录方式</div>
    <div class="auth-alt__list">
      <?php foreach ($oauthList as $k => $p): ?>
        <a class="auth-alt__btn" href="<?= e($base) ?>/oauth.php?provider=<?= e($k) ?>&amp;action=login" title="使用<?= e($p['name']) ?>登录">
          <span class="auth-alt__dot" style="background:<?= e($p['color']) ?>"><?= e(mb_substr($p['name'], 0, 1)) ?></span>
          通行证登录 · <?= e($p['name']) ?>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<script src="<?= e($base) ?>/assets/passkey.js?v=20261015-pk"></script>
<script>
(function(){
  var base = '<?= e($base) ?>';
  var appId = '<?= e($appId) ?>', redirect = '<?= e($redirect) ?>';
  var showCap = <?= $showCap ? 'true' : 'false' ?>;
  var passkeyOn = <?= $passkeyUsable ? 'true' : 'false' ?>;
  var loginCodeAvail = <?= $loginCodeAvail ? 'true' : 'false' ?>;
  var codePlaceholder = '<?= e($codePlaceholder) ?>';
  var pwdPlaceholder = '手机号 / 用户名 / 邮箱';
  var loginMode = 'pwd'; // 'pwd' or 'code'

  // 人机验证：统一模块（slide/click/icon/passive/digits/mixed，由后台 captcha_style 决定）
  UicCaptcha.init({
    req: function(){ return App.req.apply(App, arguments); },
    api: '<?= e($capApi) ?>',
    show: showCap,
    style: '<?= e($capStyle) ?>',
    fallback: '<?= e($capFallback) ?>'
  });

  var btn = document.getElementById('loginBtn');
  var agree = document.getElementById('agree');

  // 协议链接点击不触发 label 的勾选切换
  document.querySelectorAll('.auth-agree a').forEach(function(a){
    a.addEventListener('click', function(ev){ ev.stopPropagation(); });
  });

  function refreshBtnState(){
    var filled;
    if (loginMode === 'code') {
      filled = App.val('#account') && App.val('#loginCode') && agree.checked
        && (!showCap || UicCaptcha.ready());
    } else {
      filled = App.val('#account') && App.val('#password') && agree.checked
        && (!showCap || UicCaptcha.ready());
    }
    btn.disabled = !filled;
  }
  ['#account', '#password', '#loginCode'].forEach(function(sel){
    var el = document.querySelector(sel);
    if (el) el.addEventListener('input', refreshBtnState);
  });
  agree.addEventListener('change', refreshBtnState);
  // 验证码模块状态变化（无感通过 / 点选完成）时同步按钮态
  if (showCap) {
    setInterval(refreshBtnState, 400);
  }

  function doLogin(){
    var d = {
      account: App.val('#account'),
      password: App.val('#password'),
      remember: document.getElementById('remember').checked ? 1 : 0,
      app_id: appId
    };
    if (!agree.checked) { App.toast('请先阅读并同意服务条款和隐私政策', 'err'); return; }
    if (!d.account || !d.password) { App.toast('请输入账号和密码', 'err'); return; }
    if (showCap) {
      if (!UicCaptcha.ready()) { App.toast('请完成安全验证', 'err'); return; }
      d.captcha_token = UicCaptcha.token;
      d.captcha_answer = UicCaptcha.answer();
    }
    btn.disabled = true; btn.innerHTML = '<span class="spin"></span>登录中';
    App.req('user','login', d, {okMsg:false}).then(function(res){
      if (res.code !== 0) {
        btn.disabled = false; btn.innerHTML = '登 录';
        if (showCap) UicCaptcha.reset();
        refreshBtnState();
        return;
      }
      if (document.getElementById('kickAll').checked) {
        App.req('user','logoutall',{},{okMsg:false}).then(function(){
          App.req('user','login', d, {okMsg:false}).then(function(){ afterLogin(); });
        });
      } else {
        afterLogin();
      }
    });
  }

  function doCodeLogin(){
    var d = {
      target: App.val('#account'),
      code: App.val('#loginCode'),
      remember: document.getElementById('remember').checked ? 1 : 0,
      app_id: appId
    };
    if (!agree.checked) { App.toast('请先阅读并同意服务条款和隐私政策', 'err'); return; }
    if (!d.target || !d.code) { App.toast('请输入邮箱/手机号和验证码', 'err'); return; }
    if (showCap) {
      if (!UicCaptcha.ready()) { App.toast('请完成安全验证', 'err'); return; }
      d.captcha_token = UicCaptcha.token;
      d.captcha_answer = UicCaptcha.answer();
    }
    btn.disabled = true; btn.innerHTML = '<span class="spin"></span>登录中';
    App.req('user','login_code', d, {okMsg:false}).then(function(res){
      if (res.code !== 0) {
        btn.disabled = false; btn.innerHTML = '登 录';
        if (showCap) UicCaptcha.reset();
        refreshBtnState();
        return;
      }
      if (document.getElementById('kickAll').checked) {
        App.req('user','logoutall',{},{okMsg:false}).then(function(){
          App.req('user','login_code', d, {okMsg:false}).then(function(){ afterLogin(); });
        });
      } else {
        afterLogin();
      }
    });
  }

  document.getElementById('loginForm').addEventListener('submit', function(ev){
    ev.preventDefault();
    if (btn.disabled) {
      if (!agree.checked) { App.toast('请先阅读并同意服务条款和隐私政策', 'err'); return; }
      if (showCap && !UicCaptcha.ready()) { App.toast('请完成安全验证', 'err'); return; }
      return;
    }
    if (loginMode === 'code') { doCodeLogin(); } else { doLogin(); }
  });

  function afterLogin(){
    App.toast('登录成功', 'ok');
    setTimeout(function(){
      if (appId && redirect) {
        location.href = base + '/index.php?c=sso&a=authorize&app_id=' + encodeURIComponent(appId) + '&redirect=' + encodeURIComponent(redirect);
      } else if (appId) {
        location.href = base + '/index.php?c=sso&a=authorize&app_id=' + encodeURIComponent(appId);
      } else {
        location.href = base + '/index.php';
      }
    }, 450);
  }

  // ---- 验证码登录：Tab 切换 + 发送验证码 ----
  if (loginCodeAvail) {
    var tabPwd = document.getElementById('tab-pwd');
    var tabCode = document.getElementById('tab-code');
    var fieldPwd = document.getElementById('password').closest('.auth-field'); // 密码字段所在 div
    var fieldCode = document.getElementById('field-code');
    var sendCodeBtn = document.getElementById('sendCodeBtn');
    var accountInput = document.getElementById('account');
    var codeInput = document.getElementById('loginCode');
    var pkBtnEl = document.getElementById('passkeyBtn');

    function switchMode(mode){
      loginMode = mode;
      if (mode === 'code') {
        tabPwd.classList.remove('on'); tabCode.classList.add('on');
        fieldPwd.style.display = 'none'; fieldCode.style.display = '';
        accountInput.placeholder = codePlaceholder;
        accountInput.setAttribute('autocomplete', 'email');
        if (pkBtnEl) pkBtnEl.style.display = 'none';
      } else {
        tabCode.classList.remove('on'); tabPwd.classList.add('on');
        fieldCode.style.display = 'none'; fieldPwd.style.display = '';
        accountInput.placeholder = pwdPlaceholder;
        accountInput.setAttribute('autocomplete', passkeyOn ? 'username webauthn' : 'username');
        if (pkBtnEl && passkeyOn && Passkey.isSupported()) pkBtnEl.style.display = '';
      }
      refreshBtnState();
    }
    tabPwd.addEventListener('click', function(){ switchMode('pwd'); });
    tabCode.addEventListener('click', function(){ switchMode('code'); });

    // 发送验证码 + 倒计时
    var codeTimer = null;
    sendCodeBtn.addEventListener('click', function(){
      var target = App.val('#account');
      if (!target) { App.toast('请先输入' + codePlaceholder, 'err'); return; }
      if (showCap && !UicCaptcha.ready()) { App.toast('请先完成安全验证', 'err'); return; }
      var d = { target: target };
      if (showCap) { d.captcha_token = UicCaptcha.token; d.captcha_answer = UicCaptcha.answer(); }
      sendCodeBtn.disabled = true;
      App.req('user','send_login_code', d, {okMsg:false}).then(function(res){
        if (res.code !== 0) {
          sendCodeBtn.disabled = false;
          App.toast(res.message || '发送失败', 'err');
          return;
        }
        App.toast(res.message || '验证码已发送', 'ok');
        var wait = 60;
        sendCodeBtn.textContent = wait + 's';
        codeTimer = setInterval(function(){
          wait--;
          sendCodeBtn.textContent = wait + 's';
          if (wait <= 0) {
            clearInterval(codeTimer); codeTimer = null;
            sendCodeBtn.disabled = false; sendCodeBtn.textContent = '获取验证码';
          }
        }, 1000);
      });
    });
  }

  // ---- 通行密钥登录 ----
  if (passkeyOn && Passkey.isSupported()) {
    var pkBtn = document.getElementById('passkeyBtn');
    if (pkBtn) pkBtn.style.display = '';

    function passkeyLogin(remember) {
      var old = pkBtn.innerHTML;
      pkBtn.disabled = true; pkBtn.innerHTML = '<span class="spin"></span>验证中';
      Passkey.auth({ remember: !!remember, app_id: appId }).then(function (res) {
        pkBtn.disabled = false; pkBtn.innerHTML = old;
        if (res.code === 0) {
          afterLogin();
        } else {
          App.toast(res.message || '通行密钥登录失败', 'err');
        }
      }).catch(function (err) {
        pkBtn.disabled = false; pkBtn.innerHTML = old;
        if (err && err.message && err.message.indexOf('cancel') < 0) {
          App.toast(err.message, 'err');
        }
      });
    }

    if (pkBtn) {
      pkBtn.addEventListener('click', function () {
        passkeyLogin(document.getElementById('remember').checked);
      });
    }

    // 条件 UI 自动填充：页面加载后静默探测，用户聚焦账号框时浏览器自动弹出通行密钥
    Passkey.conditionalMediation({
      remember: true,
      app_id: appId,
      onSuccess: function () { afterLogin(); }
    });
  }
})();
</script>
