<?php extract($v);
$showCap = 0; // 注册页不使用图形验证码（保持简洁；如需可在后台开启登录验证码后复用同一组件）
$termsUrl   = ($base ?? '') . '/index.php?c=page&a=terms';
$privacyUrl = ($base ?? '') . '/index.php?c=page&a=privacy';
?>
<div class="auth-card">
  <div class="auth-card__logo" aria-hidden="true">
    <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
  </div>
  <h1 class="auth-card__title">注册账号</h1>

  <form id="regForm" novalidate>
    <div class="auth-field">
      <input class="auth-input" id="username" name="username" placeholder="用户名（3-15 位，中文/字母/数字/下划线）"
             autocomplete="username" required autofocus>
      <div class="inline-msg" id="tipUsername"></div>
    </div>
    <div class="auth-field">
      <input class="auth-input" id="password" name="password" type="password" placeholder="密码（6-32 位，不含空格）"
             autocomplete="new-password" required>
      <div class="inline-msg" id="tipPassword"></div>
    </div>
    <div class="auth-field">
      <input class="auth-input" id="confirm" name="confirm" type="password" placeholder="确认密码"
             autocomplete="new-password" required>
      <div class="inline-msg" id="tipConfirm"></div>
    </div>
    <div class="auth-field">
      <input class="auth-input" id="email" name="email" type="email" placeholder="邮箱（可选，用于找回密码）">
      <div class="inline-msg" id="tipEmail"></div>
    </div>
    <div class="auth-field">
      <input class="auth-input" id="mobile" name="mobile" placeholder="手机号（可选，绑定后可用手机号登录）">
      <div class="inline-msg" id="tipMobile"></div>
    </div>
    <div class="auth-field">
      <input class="auth-input" id="nickname" name="nickname" placeholder="昵称（可选，留空则与用户名相同）">
    </div>

    <div class="auth-agree">
      <label class="auth-check">
        <input type="checkbox" id="agree">
        <span>已阅读并同意<a href="<?= e($termsUrl) ?>" target="_blank" rel="noopener">《服务条款》</a>和<a href="<?= e($privacyUrl) ?>" target="_blank" rel="noopener">《隐私政策》</a></span>
      </label>
    </div>

    <button class="auth-btn" type="submit" id="regBtn" disabled>注 册</button>
  </form>

  <div class="auth-switch">
    <a href="<?= e($base) ?>/index.php?c=user&amp;a=login">登录</a><span class="auth-switch__sep">/</span><span class="on">注册</span>
  </div>
</div>

<script>
(function(){
  var base = '<?= e($base) ?>';
  var agree = document.getElementById('agree');
  var btn = document.getElementById('regBtn');

  document.querySelectorAll('.auth-agree a').forEach(function(a){
    a.addEventListener('click', function(ev){ ev.stopPropagation(); });
  });

  function refreshBtnState(){
    var filled = App.val('#username') && App.val('#password') && App.val('#confirm') && agree.checked;
    btn.disabled = !filled;
  }
  ['#username', '#password', '#confirm'].forEach(function(sel){
    document.querySelector(sel).addEventListener('input', refreshBtnState);
  });
  agree.addEventListener('change', refreshBtnState);

  function check(field, id){
    var v = App.val('#' + id);
    if (!v) { document.getElementById('tip' + id.charAt(0).toUpperCase() + id.slice(1)).textContent = ''; return; }
    App.req('user','check',{field:field, value:v},{okMsg:false}).then(function(res){
      var el = document.getElementById('tip' + id.charAt(0).toUpperCase() + id.slice(1));
      if (res.code === 0 && res.data) {
        el.className = 'inline-msg ' + (res.data.available ? 'ok' : 'err');
        el.textContent = res.data.message;
      }
    });
  }
  ['username','email','mobile'].forEach(function(id){
    document.getElementById(id).addEventListener('blur', function(){ check(id, id); });
  });
  document.getElementById('confirm').addEventListener('blur', function(){
    var t = document.getElementById('tipConfirm');
    if (App.val('#confirm') && App.val('#confirm') !== App.val('#password')) {
      t.className = 'inline-msg err'; t.textContent = '两次输入的密码不一致';
    } else { t.className = 'inline-msg ok'; t.textContent = ''; }
  });

  document.getElementById('regForm').addEventListener('submit', function(ev){
    ev.preventDefault();
    if (!agree.checked) { App.toast('请先阅读并同意服务条款和隐私政策', 'err'); return; }
    if (App.val('#password') !== App.val('#confirm')) { App.toast('两次输入的密码不一致', 'err'); return; }
    var d = {
      username: App.val('#username'),
      password: App.val('#password'),
      confirm: App.val('#confirm'),
      email: App.val('#email'),
      mobile: App.val('#mobile'),
      nickname: App.val('#nickname')
    };
    btn.disabled = true; btn.innerHTML = '<span class="spin"></span>注册中';
    App.req('user','register', d, {okMsg:false}).then(function(res){
      btn.disabled = false; btn.innerHTML = '注 册';
      if (res.code !== 0) { App.toast(res.message, 'err'); refreshBtnState(); return; }
      App.toast('注册成功，已自动登录', 'ok');
      setTimeout(function(){ location.href = base + '/index.php'; }, 700);
    });
  });
})();
</script>
