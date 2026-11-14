<?php extract($v);
$showCap  = Config::get('admin_login_captcha', true) ? 1 : 0;
// 管理员找回密码入口：总开关关闭时连链接一起隐藏（避免给出一个必然降级的页面）
$findpwdOn = intval(Config::get('admin_findpwd_enabled', 1)) ? 1 : 0;
$capStyle = (string)Config::get('captcha_style', 'slide');
$capFallback = (string)Config::get('captcha_fallback', 'slide');
$capApi   = ($base ?? '') . '/api/index.php';
?>
<div class="login-page">
<div class="login-box">
  <div class="login-brand">
    <div class="logo">U</div>
    <div class="title"><?= e(site_name()) ?></div>
    <div class="subtitle">管理后台安全登录</div>
  </div>

  <div class="login-card">
    <div class="login-card__body">
      <form id="loginForm">
        <div class="field" id="userField">
          <label class="label" for="username">管理员账号</label>
          <input class="input" id="username" autocomplete="username" required autofocus placeholder="请输入管理员账号">
        </div>
        <div class="field" id="pwdField">
          <label class="label" for="password">登录密码</label>
          <input class="input" id="password" type="password" autocomplete="current-password" required placeholder="请输入登录密码">
          <?php if ($findpwdOn): ?>
          <!-- 忘记密码：密码框下方右对齐，跳转后台找回密码（邮件验证码自助重置） -->
          <div class="login-forgot">
            <a href="<?= e($base) ?>/admin.php?c=findpwd">忘记密码？</a>
          </div>
          <?php endif; ?>
        </div>

        <!-- 安全验证区域（partials/captcha_card.php + assets/captcha.js） -->
        <div class="field <?= $showCap ? '' : 'hide' ?>" id="capField">
          <?php $capShow = $showCap ? 1 : 0; include __DIR__ . '/../partials/captcha_card.php'; ?>
        </div>

        <div class="field hide" id="totpField">
          <label class="label" for="totp_code">动态验证码（TOTP）</label>
          <input class="input" id="totp_code" inputmode="numeric" maxlength="6" placeholder="打开认证器输入 6 位动态码" autocomplete="one-time-code">
          <div class="hint">账号密码已验证通过，请输入绑定认证器生成的动态码完成登录</div>
        </div>

        <button class="btn btn-p btn-block" type="submit" id="loginBtn">登 录</button>
      </form>
    </div>
  </div>

  <div class="login-footer">
    请妥善保管管理员账号密码，定期修改以保障安全
  </div>
</div>
</div>

<script>
(function(){
  var base = '<?= e($base) ?>';
  var showCap = <?= $showCap ? 'true' : 'false' ?>;
  var step = 1;

  // 人机验证：统一模块（slide/click/icon/passive/digits/mixed，由后台 captcha_style 决定）
  // 注意：Admin 由布局在文档末尾定义，req 必须惰性求值，不能在初始化时直接 bind
  UicCaptcha.init({
    req: function(){ return Admin.req.apply(Admin, arguments); },
    api: '<?= e($capApi) ?>',
    show: showCap,
    active: function(){ return step === 1; },
    style: '<?= e($capStyle) ?>',
    fallback: '<?= e($capFallback) ?>'
  });

  function isCaptchaReady(){
    if (!showCap || step !== 1) return true;
    return UicCaptcha.ready();
  }

  document.getElementById('loginForm').addEventListener('submit', function(ev){
    ev.preventDefault();
    if (step === 2) {
      var tc = document.getElementById('totp_code').value.trim();
      if (!/^\d{6}$/.test(tc)) { Admin.toast('请输入 6 位动态验证码', 'err'); return; }
    }
    var d = {
      username: document.getElementById('username').value.trim(),
      password: document.getElementById('password').value.trim()
    };
    if (step === 1 && showCap) {
      if (!isCaptchaReady()) { Admin.toast('请完成安全验证', 'err'); return; }
      d.captcha_token = UicCaptcha.token;
      d.captcha_answer = UicCaptcha.answer();
    }
    if (step === 2) d.totp_code = document.getElementById('totp_code').value.trim();

    var btn = document.getElementById('loginBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spin"></span>' + (step === 2 ? '验证中' : '登录中');
    Admin.req('admin','login', d, {okMsg:false}).then(function(res){
      btn.disabled = false;
      if (res.code !== 0) {
        btn.innerHTML = step === 2 ? '验 证' : '登 录';
        Admin.toast(res.message || '登录失败','err');
        if (step === 1 && showCap) UicCaptcha.reset();
        return;
      }
      if (res.data && res.data.need_totp) {
        step = 2;
        document.getElementById('totpField').classList.remove('hide');
        document.getElementById('capField').classList.add('hide');
        btn.innerHTML = '验 证';
        document.getElementById('totp_code').focus();
        return;
      }
      // 密码超期策略：到期则引导去改密页（首登强制改密已移除）
      var dest = (res.data && res.data.need_pwd_change)
        ? base + '/admin.php?c=changepwd'
        : base + '/admin.php';
      location.href = dest;
    });
  });
})();
</script>
