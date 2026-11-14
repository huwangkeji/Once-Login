<?php extract($v); ?>
<div class="wrap wrap-narrow narrow-stage">
  <div class="narrow-inner">
  <div class="card">
    <div class="card-h">修改密码</div>
    <div class="card-b">
      <form id="pwdForm">
        <div class="field">
          <label class="label" for="old_password">原密码</label>
          <input class="input" id="old_password" type="password" autocomplete="current-password" required>
        </div>
        <div class="field">
          <label class="label" for="new_password">新密码</label>
          <input class="input" id="new_password" type="password" autocomplete="new-password" required>
          <div class="hint">8-32 位，且同时包含字母和数字。</div>
        </div>
        <div class="field">
          <label class="label" for="confirm_password">确认新密码</label>
          <input class="input" id="confirm_password" type="password" autocomplete="new-password" required>
        </div>
        <button class="btn btn-p btn-block" type="submit">确认修改</button>
      </form>
    </div>
  </div>
  </div>
</div>
<script>
document.getElementById('pwdForm').addEventListener('submit', function(ev){
  ev.preventDefault();
  var p1 = document.getElementById('new_password').value;
  var p2 = document.getElementById('confirm_password').value;
  if (p1 !== p2) { Admin.toast('两次输入的新密码不一致', 'err'); return; }
  var btn = this.querySelector('[type=submit]');
  btn.disabled = true; btn.innerHTML = '<span class="spin"></span>提交中';
  Admin.req('admin','change_pwd',{
    old_password: document.getElementById('old_password').value,
    new_password: p1
  },{okMsg:false}).then(function(res){
    btn.disabled = false; btn.innerHTML = '确认修改';
    if (res.code !== 0) { Admin.toast(res.message || '修改失败', 'err'); return; }
    Admin.toast('密码修改成功', 'ok');
    setTimeout(function(){ location.href = '<?= e($base) ?>/admin.php'; }, 900);
  });
});
</script>