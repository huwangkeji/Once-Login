<?php extract($v);
$pwdDays = intval(Config::get('admin_pwd_expire_days', 0));
?>
<div class="card accent" style="margin-bottom:14px">
  <div class="card-h">
    <span><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg> 个人安全中心</span>
  </div>
</div>

<div class="grid g2" style="margin-bottom:14px">
  <div class="card">
    <div class="card-h">两步验证（TOTP）</div>
    <div class="card-b">
      <div id="totpPanel">
        <div class="empty">状态加载中…</div>
      </div>
    </div>
  </div>
  <div class="card">
    <div class="card-h">密码安全策略</div>
    <div class="card-b">
      <div class="kv">
        <dt>密码有效期</dt><dd><?= $pwdDays > 0 ? $pwdDays . ' 天（超期后登录需强制改密）' : '不限（由系统设置统一管控）' ?></dd>
        <dt>复杂度要求</dt><dd>不少于 8 位，须同时包含字母与数字</dd>
        <dt>历史密码</dt><dd>最近 5 次密码不可重用</dd>
        <dt>建议</dt><dd class="muted">请勿与其他系统共用口令；如发现异常登录请立即修改密码并开启两步验证。</dd>
      </div>
      <div class="divider"></div>
      <a class="btn btn-p" href="<?= e($base) ?>/admin.php?c=changepwd">立即修改密码</a>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-h">最近的安全活动记录</div>
  <div class="card-b tight">
    <table class="table">
      <thead><tr><th>时间</th><th>类型</th><th>目标 / 账户</th><th>描述</th></tr></thead>
      <tbody id="actBody"><tr><td colspan="4" class="empty">加载中…</td></tr></tbody>
    </table>
  </div>
</div>

<script>
(function(){
  function esc(s){ return Admin.esc(s); }
  var stepPanel = document.getElementById('totpPanel');
  function renderStatus(){
    Admin.req('admin','totp_status',{},{okMsg:false}).then(function(res){
      if (res.code !== 0) return;
      var d = res.data;
      if (d.enabled) {
        stepPanel.innerHTML = '<div class="center" style="text-align:center;padding:10px 0">'
          + '<span class="badge badge-ok" style="font-size:13px;padding:5px 14px">已启用两步验证</span>'
          + '<div class="muted" style="font-size:12.5px;margin-top:12px">每次后台登录将要求输入认证器生成的 6 位动态验证码。</div>'
          + '<div style="margin-top:14px"><button class="btn btn-d" id="totpDisableBtn" type="button">关闭两步验证</button></div>'
          + '</div>';
        document.getElementById('totpDisableBtn').onclick = function(){
          var code = prompt('请输入当前认证器的 6 位动态验证码（确认操作者身份）');
          if (!code) return;
          Admin.req('admin','totp_disable',{code:code},{okMsg:false,after:function(){ renderStatus(); }});
        };
      } else {
        stepPanel.innerHTML =
          '<div class="muted" style="font-size:13px">两步验证可显著提升后台安全等级：即便口令泄露，攻击者也无法登录。</div>'
          + '<div style="margin-top:12px"><button class="btn btn-p" id="totpSetupBtn" type="button">启用两步验证</button></div>'
          + '<div class="hint" style="margin-top:8px">绑定后将要求手机认证器（如 Google Authenticator / Microsoft Authenticator）每次登录生成动态码。</div>';
        document.getElementById('totpSetupBtn').onclick = function(){
          stepPanel.innerHTML = '<div class="empty">正在生成绑定密钥…</div>';
          Admin.req('admin','totp_setup',{},{okMsg:false}).then(function(res){
            if (res.code !== 0) { renderStatus(); return; }
            var d = res.data;
            stepPanel.innerHTML =
              '<div class="alert alert-info" style="font-size:12.5px">在认证器中「扫描二维码」不便时，可手动添加：类型 TOTP、账户 UICenter（<?= e($admin['username']) ?>）。</div>'
              + '<div class="field"><label class="label">账户标签</label><input class="input mono" readonly value="<?= e($admin['username']) ?>@uic"></div>'
              + '<div class="field"><label class="label">密钥 Base32（<b>请立即妥善保存</b>）</label>'
              + '<div class="row"><input class="input mono" id="totpSecret" readonly value="' + esc(d.secret) + '"><button class="btn btn-s" id="copySec" type="button">复制</button></div></div>'
              + '<div class="field"><label class="label">otpauth 链接（供支持扫码的认证器使用）</label>'
              + '<textarea class="input mono" id="totpUri" readonly style="min-height:44px">' + esc(d.uri) + '</textarea></div>'
              + '<div class="field"><label class="label" for="bindCode">输入认证器当前显示的 6 位动态码完成绑定</label>'
              + '<input class="input" id="bindCode" inputmode="numeric" maxlength="6" placeholder="6 位动态码"></div>'
              + '<div class="row"><button class="btn btn-p" id="totpEnableBtn" type="button">验证并启用</button>'
              + '<button class="btn" type="button" id="totpBackBtn">返回</button></div>';
            document.getElementById('copySec').onclick = function(){
              var s = document.getElementById('totpSecret');
              s.select(); document.execCommand('copy');
              Admin.toast('密钥已复制', 'ok');
            };
            document.getElementById('totpEnableBtn').onclick = function(){
              var code = document.getElementById('bindCode').value.trim();
              if (!/^\d{6}$/.test(code)) { Admin.toast('请输入 6 位动态验证码', 'err'); return; }
              Admin.req('admin','totp_enable',{code:code},{okMsg:false,after:function(res){
                if (res.code === 0) renderStatus();
              }});
            };
            document.getElementById('totpBackBtn').onclick = renderStatus;
          });
        };
      }
    });
  }
  renderStatus();

  Admin.req('admin','log_list',{type:'admin',page:1,size:10},{okMsg:false}).then(function(res){
    var body = document.getElementById('actBody');
    if (res.code !== 0 || !res.data || !res.data.rows.length) {
      body.innerHTML = '<tr><td colspan="4" class="empty">暂无记录（当前角色可能无日志查看权限）</td></tr>';
      return;
    }
    body.innerHTML = res.data.rows.map(function(r){
      var extra = r.extra && r.extra.length ? ' ' + esc(JSON.stringify(r.extra)) : '';
      return '<tr><td class="nowrap">' + new Date(r.created_at * 1000).toLocaleString('zh-CN', {hour12:false}) + '</td>'
        + '<td><span class="mono">' + esc(r.type) + '</span></td>'
        + '<td>' + esc(r.target || '-') + '</td>'
        + '<td>' + esc(r.message) + extra + '</td></tr>';
    }).join('');
  });
})();
</script>