<?php extract($v);
$enabled     = !empty($v['enabled']);
$reason      = (string)($v['disabled_reason'] ?? '');
$contact     = (string)($v['contact_email'] ?? '');
$showCap     = Config::get('admin_login_captcha', true) ? 1 : 0;
$capStyle    = (string)Config::get('captcha_style', 'slide');
$capFallback = (string)Config::get('captcha_fallback', 'slide');
$capApi      = ($base ?? '') . '/api/index.php';
$loginUrl    = ($base ?? '') . '/admin.php?c=login';
?>
<div class="login-page">
<div class="login-box">
  <div class="login-brand">
    <div class="logo">U</div>
    <div class="title"><?= e(site_name()) ?></div>
    <div class="subtitle">管理员找回密码</div>
  </div>

  <div class="login-card">
    <div class="login-card__body">
      <?php if (!$enabled): ?>
      <!-- 优雅降级：邮件未开启 / 无人设置邮箱 / 功能关闭时不放假表单，直接给出人工途径 -->
      <div class="login-degraded">
        <div class="login-degraded__icon" aria-hidden="true">
          <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 8v5"/><path d="M12 16.5v.01"/></svg>
        </div>
        <div class="login-degraded__title">暂不支持在线自助找回</div>
        <p class="login-degraded__text">
          原因：<?= e($reason) ?>。<br>
          请联系超级管理员在后台「管理员」中为你重置密码<?= $contact ? '，或发送邮件至 <b>' . e($contact) . '</b>' : '' ?>。
        </p>
      </div>
      <a class="btn btn-p btn-block login-btn-link" href="<?= e($loginUrl) ?>">返回登录</a>
      <?php else: ?>

      <!-- 步骤一：验证身份（账号 + 预留邮箱 + 邮箱验证码） -->
      <div id="fpStep1">
        <div class="field">
          <label class="label" for="fp_username">管理员账号</label>
          <input class="input" id="fp_username" autocomplete="username" placeholder="请输入管理员账号" autofocus>
        </div>
        <div class="field">
          <label class="label" for="fp_email">预留邮箱</label>
          <input class="input" id="fp_email" type="email" autocomplete="email" placeholder="该管理员在后台设置的邮箱">
        </div>

        <!-- 安全验证区域（partials/captcha_card.php + assets/captcha.js） -->
        <div class="field <?= $showCap ? '' : 'hide' ?>" id="fpCapField">
          <?php $capShow = $showCap ? 1 : 0; include __DIR__ . '/../partials/captcha_card.php'; ?>
        </div>

        <div class="field">
          <label class="label" for="fp_code">邮箱验证码</label>
          <div class="login-code-row">
            <input class="input" id="fp_code" inputmode="numeric" maxlength="6" autocomplete="one-time-code" placeholder="6 位验证码">
            <button class="btn btn-s" type="button" id="fpSend">获取验证码</button>
          </div>
        </div>
        <div class="hint" id="fpHint">验证码将发送至该管理员的预留邮箱，10 分钟内有效。</div>

        <button class="btn btn-p btn-block" type="button" id="fpNext" style="margin-top:14px">下一步</button>
      </div>

      <!-- 步骤二：设置新密码 -->
      <div id="fpStep2" class="hide">
        <div class="field">
          <label class="label" for="fp_npwd">新密码</label>
          <input class="input" id="fp_npwd" type="password" autocomplete="new-password" placeholder="8-32 位，需同时含字母和数字">
        </div>
        <div class="field">
          <label class="label" for="fp_npwd2">确认新密码</label>
          <input class="input" id="fp_npwd2" type="password" autocomplete="new-password" placeholder="请再次输入新密码">
        </div>
        <div class="hint">重置成功后，该管理员在其它设备的后台登录状态将立即失效，需用新密码重新登录。</div>

        <button class="btn btn-p btn-block" type="button" id="fpReset" style="margin-top:14px">确认重置</button>
        <button class="btn btn-block" type="button" id="fpBack" style="margin-top:10px">返回上一步</button>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="login-footer">
    <a href="<?= e($loginUrl) ?>">返回登录</a>
  </div>
</div>
</div>

<?php if ($enabled): ?>
<script>
(function(){
  var showCap = <?= $showCap ? 'true' : 'false' ?>;
  var loginUrl = '<?= e($loginUrl) ?>';

  // 人机验证：统一模块（slide/click/icon/passive/digits/mixed，由后台 captcha_style 决定）
  // 注意：Admin 由布局在文档末尾定义，req 必须惰性求值，不能在初始化时直接 bind
  UicCaptcha.init({
    req: function(){ return Admin.req.apply(Admin, arguments); },
    api: '<?= e($capApi) ?>',
    show: showCap,
    style: '<?= e($capStyle) ?>',
    fallback: '<?= e($capFallback) ?>'
  });

  function val(id){ var el = document.getElementById(id); return el ? el.value.trim() : ''; }
  var hint = document.getElementById('fpHint');

  // 获取验证码：60 秒倒计时，与前台找回密码交互一致
  var sending = false, cdTimer = null, btnSend = document.getElementById('fpSend');
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
    if (!val('fp_username')) { Admin.toast('请输入管理员账号', 'err'); return; }
    if (!val('fp_email'))    { Admin.toast('请输入预留邮箱', 'err'); return; }
    if (sending) return;
    if (showCap && !UicCaptcha.ready()) { Admin.toast('请完成安全验证', 'err'); return; }
    sending = true;
    btnSend.disabled = true;
    var data = { username: val('fp_username'), email: val('fp_email') };
    if (showCap) {
      data.captcha_token  = UicCaptcha.token;
      data.captcha_answer = UicCaptcha.answer();
    }
    Admin.req('admin','findpwd_send', data, {okMsg:false}).then(function(res){
      sending = false;
      btnSend.disabled = false;
      if (!res) return;
      if (res.code !== 0) {
        if (showCap) UicCaptcha.reset();
        return;
      }
      startCountdown(60);
      if (res.data && res.data.debug_code) {
        hint.innerHTML = '调试模式验证码：<b class="mono">' + res.data.debug_code + '</b>（10 分钟内有效）';
      } else {
        hint.textContent = res.message || '验证码已发送';
      }
    });
  });

  function go(step){
    document.getElementById('fpStep1').classList.toggle('hide', step !== 1);
    document.getElementById('fpStep2').classList.toggle('hide', step !== 2);
  }
  document.getElementById('fpNext').addEventListener('click', function(){
    if (!val('fp_username')) { Admin.toast('请输入管理员账号', 'err'); return; }
    if (!val('fp_email'))    { Admin.toast('请输入预留邮箱', 'err'); return; }
    if (!val('fp_code'))     { Admin.toast('请输入邮箱验证码', 'err'); return; }
    go(2);
    document.getElementById('fp_npwd').focus();
  });
  document.getElementById('fpBack').addEventListener('click', function(){ go(1); });

  document.getElementById('fpReset').addEventListener('click', function(){
    var d = {
      username: val('fp_username'),
      email: val('fp_email'),
      code: val('fp_code'),
      new_password: val('fp_npwd'),
      confirm: val('fp_npwd2')
    };
    if (!d.new_password) { Admin.toast('请输入新密码', 'err'); return; }
    if (d.new_password !== d.confirm) { Admin.toast('两次输入的新密码不一致', 'err'); return; }
    var btn = this; btn.disabled = true; btn.innerHTML = '<span class="spin"></span>提交中';
    Admin.req('admin','findpwd_reset', d, {okMsg:false}).then(function(res){
      btn.disabled = false; btn.innerHTML = '确认重置';
      if (!res) return;
      if (res.code !== 0) { Admin.toast(res.message || '重置失败', 'err'); return; }
      Admin.toast('密码已重置，请重新登录', 'ok');
      setTimeout(function(){ location.href = loginUrl; }, 900);
    });
  });
})();
</script>
<?php endif; ?>
