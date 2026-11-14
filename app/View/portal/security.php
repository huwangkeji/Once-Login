<?php extract($v);
$u  = $v['user'];
$sec = $v['sec'];
$deviceCount = count($sec['devices']);
$passkeyOn = (int) Config::get('passkey_enabled', 0) === 1;
// 当前访问域是否可用于 WebAuthn（IP 访问 / 非 https / 域不匹配时给出明确指引，而不是让浏览器报英文错）
$pkIssue = $passkeyOn ? PasskeyService::domainIssue() : null;
?>
<div class="mc-page">
  <!-- 页头：标题 + 一句话说明 -->
  <div class="mc-ph">
    <div>
      <h2 class="mc-page__title">账号安全</h2>
      <p class="mc-ph__desc">管理登录密码、通行密钥、第三方绑定与在线设备，发现异常可随时一键下线。</p>
    </div>
  </div>

  <!-- 账号状态 -->
  <div class="mc-row">
    <span class="mc-row__icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg></span>
    <div class="mc-row__body">
      <div class="mc-row__label">账号状态 <span class="badge <?= $u['status'] === 'normal' ? 'badge-ok' : 'badge-err' ?>"><?= e($u['status_text']) ?></span></div>
      <div class="mc-row__value">
        最近登录 <?= e($u['last_login_text']) ?> · IP <span class="mono"><?= e($u['last_login_ip']) ?></span> ·
        在线设备 <b id="deviceCount"><?= $deviceCount ?></b> 台 · 数据源 <?= e($u['source']) ?>
      </div>
    </div>
  </div>

  <!-- 修改密码 -->
  <div class="mc-section">
    <div class="mc-section__head">
      <div>
        <div class="mc-section__title">修改密码</div>
        <div class="mc-section__desc">定期更换密码，账号更安全</div>
      </div>
      <button class="mc-act" type="button" id="btnPwdToggle">修改</button>
    </div>
    <div class="mc-editor" id="pwdEditor" style="display:none">
      <form id="pwdForm">
        <div class="field">
          <label class="label" for="oldpwd">当前密码</label>
          <div class="pf-pw">
            <input class="input" id="oldpwd" type="password" autocomplete="current-password" required>
            <button type="button" class="pw-eye" data-target="oldpwd" title="显示/隐藏">👁</button>
          </div>
        </div>
        <div class="field">
          <label class="label" for="npwd">新密码</label>
          <div class="pf-pw">
            <input class="input" id="npwd" type="password" autocomplete="new-password" required>
            <button type="button" class="pw-eye" data-target="npwd" title="显示/隐藏">👁</button>
          </div>
          <!-- 强度条 -->
          <div class="pw-meter">
            <span class="pw-bar" id="pwBar1"></span>
            <span class="pw-bar" id="pwBar2"></span>
            <span class="pw-bar" id="pwBar3"></span>
            <span class="pw-label" id="pwLabel">未输入</span>
          </div>
          <div class="hint" id="pwHint">建议 8 位以上，混合大小写字母、数字与符号</div>
        </div>
        <div class="field">
          <label class="label" for="npwd2">确认新密码</label>
          <div class="pf-pw">
            <input class="input" id="npwd2" type="password" autocomplete="new-password" required>
            <button type="button" class="pw-eye" data-target="npwd2" title="显示/隐藏">👁</button>
          </div>
          <div class="hint"><span id="pwMatch" class="pf-tip"></span></div>
        </div>
        <div class="alert alert-info" style="font-size:12.5px">修改成功后将调用 UCenter 同步，并注销该账号的全部登录状态。</div>
        <button class="btn btn-p" type="submit">确认修改</button>
      </form>
    </div>
  </div>

  <?php if ($passkeyOn): ?>
  <!-- 通行密钥 -->
  <div class="mc-section" id="passkeyCard">
    <div class="mc-section__head">
      <div>
        <div class="mc-section__title">通行密钥</div>
        <div class="mc-section__desc">使用面容/指纹等安全登录，无需密码或验证码。</div>
      </div>
      <button class="mc-act" type="button" id="pkAddBtn"
        <?= $pkIssue ? 'disabled title="' . e($pkIssue['message']) . '"' : '' ?>>＋ 添加通行密钥</button>
    </div>
    <?php if ($pkIssue): ?>
      <!-- 浏览器硬约束：IP 访问 / 非 HTTPS / RP ID 不匹配时无法使用通行密钥，这里给出可执行的修复指引 -->
      <div class="alert alert-warn" id="pkDomainNotice" style="margin:12px 0 0;font-size:12.5px">
        <b>当前访问地址无法使用通行密钥</b><br>
        <?= e($pkIssue['message']) ?><br>
        <span class="faint"><?= e($pkIssue['hint']) ?></span>
      </div>
    <?php endif; ?>
    <div id="pkEmpty" class="empty" style="display:none">尚未添加通行密钥，添加后可使用设备生物识别快速登录</div>
    <div id="pkUnsupported" class="empty" style="display:none">当前浏览器不支持通行密钥，请使用 Chrome / Edge / Safari 等现代浏览器</div>
    <div class="t-scroll">
      <table class="table compact" id="pkTable" style="display:none">
        <thead><tr><th>名称</th><th>添加时间</th><th>最近使用</th><th class="right">操作</th></tr></thead>
        <tbody id="pkBody"></tbody>
      </table>
    </div>
    <div class="hint" style="margin-top:8px">通行密钥绑定当前域名，仅可在本站使用；更换域名需重新注册。</div>
  </div>
  <?php endif; ?>

  <?php
    $oauthList  = $v['oauthList'] ?? [];
    $oauthBinds = $v['oauthBinds'] ?? [];
    $deletion   = $v['deletion'] ?? null;
    $deletionOn = !empty($v['deletionOn']);
    $boundMap   = [];
    foreach ($oauthBinds as $b) { $boundMap[$b['provider']] = $b; }
  ?>

  <?php if (!empty($oauthList) || !empty($oauthBinds)): ?>
  <!-- 第三方账号绑定 -->
  <div class="mc-section">
    <div class="mc-section__head">
      <div>
        <div class="mc-section__title">第三方账号</div>
        <div class="mc-section__desc">绑定后可使用对应平台一键登录本站</div>
      </div>
    </div>
    <?php foreach ($oauthList as $k => $p): $has = isset($boundMap[$k]); ?>
    <div class="mc-row">
      <span class="mc-row__icon"><span class="oauth-dot" style="background:<?= e($p['color']) ?>"><?= e(mb_substr($p['name'], 0, 1)) ?></span></span>
      <div class="mc-row__body">
        <div class="mc-row__label"><?= e($p['name']) ?></div>
        <div class="mc-row__value"><?= $has ? '已绑定' : '未绑定' ?></div>
      </div>
      <?php if ($has): ?>
        <button class="mc-act" type="button" data-unbind="<?= e($k) ?>">解绑</button>
      <?php else: ?>
        <a class="mc-act" href="<?= e($base) ?>/oauth.php?provider=<?= e($k) ?>&amp;action=login">绑定</a>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- 已登录的设备 -->
  <div class="mc-section">
    <div class="mc-section__head">
      <div>
        <div class="mc-section__title">已登录的设备</div>
        <div class="mc-section__desc">在此站点使用新设备登录时，会被记录在此</div>
      </div>
      <button class="mc-act" type="button" onclick="Security.kickAll()">↩ 从所有设备退出登录</button>
    </div>
    <div id="deviceEmpty" class="empty" style="display:<?= $deviceCount ? 'none' : 'block' ?>">暂无在线设备</div>
    <?php if ($deviceCount): ?>
    <div id="deviceTable">
      <?php foreach ($sec['devices'] as $d): ?>
      <div class="mc-row dev-row<?= $d['is_current'] ? ' row-current' : '' ?>" data-sid="<?= e($d['sid']) ?>">
        <span class="mc-row__icon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg></span>
        <div class="mc-row__body">
          <div class="mc-row__label"><?= e($d['device_label']) ?>
            <?php if ($d['is_current']): ?><span class="badge badge-info" style="margin-left:4px">当前设备</span><?php endif; ?>
            <?php if ($d['remember']): ?><span class="badge badge-mute" style="margin-left:4px">记住登录</span><?php endif; ?>
          </div>
          <div class="mc-row__value"><span class="mono"><?= e($d['login_ip']) ?></span> · 登录 <?= e($d['login_text']) ?> · 活动 <?= e($d['last_text']) ?></div>
        </div>
        <?php if (!$d['is_current']): ?>
          <button class="mc-act mc-act--danger" type="button" onclick="Security.kick('<?= e($d['sid']) ?>')">退出</button>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($deletionOn): ?>
  <!-- 删除账号 -->
  <div class="mc-section">
    <div class="mc-section__head">
      <div>
        <div class="mc-section__title" style="color:#dc2626">删除账号</div>
        <div class="mc-section__desc">删除账号是不可逆的操作，请三思而后行。</div>
      </div>
      <button class="mc-act mc-act--danger" type="button" id="btnDelToggle">删除账号</button>
    </div>
    <?php if ($deletion && $deletion['status'] === 'pending'): ?>
      <div class="alert alert-warn" style="margin-top:10px">
        你已于 <?= e(human_time($deletion['created_at'])) ?> 提交注销申请，正在等待管理员审核。
        审核通过后账号将立即停用并移入回收站。
      </div>
    <?php elseif ($deletion && $deletion['status'] === 'approved'): ?>
      <div class="alert alert-err" style="margin-top:10px">你的注销申请已通过，账号已停用。</div>
    <?php elseif ($deletion && $deletion['status'] === 'rejected'): ?>
      <div class="alert alert-info" style="margin-top:10px">
        你于 <?= e(human_time($deletion['created_at'])) ?> 提交的注销申请已被驳回<?= $deletion['remark'] ? '：' . e($deletion['remark']) : '' ?>。
      </div>
    <?php endif; ?>
    <div class="mc-editor" id="delEditor" style="display:none">
      <div class="field">
        <label class="label" for="delReason">注销原因（选填）</label>
        <textarea class="input" id="delReason" rows="3" maxlength="200" placeholder="例如：不再使用该服务 / 账号存在安全隐患"></textarea>
      </div>
      <div class="alert alert-err" style="font-size:12.5px">
        注销为不可逆操作：经管理员审核通过后，账号将立即停用、全部设备下线并移入回收站，且无法再用该账号登录。
      </div>
      <button class="btn btn-d" type="button" id="btnDeleteAccount">确认提交注销申请</button>
    </div>
  </div>
  <?php endif; ?>

  <script>
  (function(){
    var ubs = document.querySelectorAll('[data-unbind]');
    for (var i = 0; i < ubs.length; i++) {
      ubs[i].addEventListener('click', function(){
        var p = this.getAttribute('data-unbind');
        if (!confirm('确定要解除该第三方账号的绑定吗？')) return;
        App.req('user','oauth_unbind',{provider:p},{okMsg:false}).then(function(res){
          if (res && res.code === 0) { App.toast('已解绑','ok'); setTimeout(function(){ location.reload(); }, 600); }
        });
      });
    }
    var bd = document.getElementById('btnDeleteAccount');
    if (bd) {
      bd.addEventListener('click', function(){
        var reason = (document.getElementById('delReason').value || '').trim();
        if (!confirm('注销申请需管理员审核，通过后账号将停用。确定提交吗？')) return;
        bd.disabled = true; bd.innerHTML = '<span class="spin"></span>提交中';
        App.req('user','deletion_request',{reason:reason},{okMsg:false}).then(function(res){
          bd.disabled = false; bd.innerHTML = '提交注销申请';
          if (res && res.code === 0) { App.toast(res.message || '已提交','ok'); setTimeout(function(){ location.reload(); }, 800); }
        });
      });
    }
    <?php $boundP = (string)Request::input('bound', ''); if ($boundP !== ''): $bp = OAuth::get($boundP); ?>
    // App 由布局脚本在 </body> 前定义，此处必须等 DOM 就绪后才能调用
    window.addEventListener('DOMContentLoaded', function(){
      App.toast('已成功绑定<?= e($bp ? $bp['name'] : '第三方') ?>账号', 'ok');
    });
    <?php endif; ?>
  })();
  </script>

  <!-- 登录历史 -->
  <div class="mc-section">
    <div class="mc-section__head">
      <div>
        <div class="mc-section__title">登录历史</div>
        <div class="mc-section__desc">最近 50 条登录与退出记录</div>
      </div>
    </div>
    <?php if (!$sec['history']): ?>
      <div class="empty">暂无登录记录</div>
    <?php else: ?>
    <table class="table compact">
      <thead><tr><th>时间</th><th>事件</th><th>IP</th><th>设备</th></tr></thead>
      <tbody>
      <?php foreach ($sec['history'] as $h): ?>
        <tr>
          <td class="nowrap faint"><?= date('m-d H:i', intval($h['created_at'])) ?></td>
          <td><?= $h['type'] === 'logout' ? '<span class="badge badge-mute">退出</span>' : '<span class="badge badge-ok">登录</span>' ?> <?= e($h['message']) ?></td>
          <td class="mono"><?= e($h['ip']) ?></td>
          <td class="faint" style="font-size:12px"><?= e(UserSession::deviceLabel($h['ua'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<script>
/* 行内编辑器开关（修改密码 / 删除账号） */
(function(){
  function bindToggle(btnId, boxId){
    var btn = document.getElementById(btnId), box = document.getElementById(boxId);
    if (!btn || !box) return;
    btn.addEventListener('click', function(){
      var open = box.style.display !== 'none';
      box.style.display = open ? 'none' : '';
      if (!open) {
        var first = box.querySelector('input,textarea');
        if (first) setTimeout(function(){ first.focus(); }, 40);
      }
    });
  }
  bindToggle('btnPwdToggle', 'pwdEditor');
  bindToggle('btnDelToggle', 'delEditor');
})();
</script>

<style>
.pf-pw{position:relative}
.pf-pw .input{padding-right:42px}
.pw-eye{position:absolute;right:6px;top:50%;transform:translateY(-50%);border:0;background:transparent;cursor:pointer;font-size:15px;opacity:.55;padding:4px 6px}
.pw-eye:hover{opacity:1}
.pw-meter{display:flex;align-items:center;gap:5px;margin-top:7px}
.pw-bar{flex:1;max-width:52px;height:5px;border-radius:3px;background:#eceef1;transition:background .2s}
.pw-bar.lv1{background:#dc2626}
.pw-bar.lv2{background:#f59e0b}
.pw-bar.lv3{background:#16a34a}
.pw-label{font-size:12px;margin-left:4px}
.pf-tip.ok{color:#16a34a}
.pf-tip.err{color:#dc2626}
.pw-hint-err{color:#dc2626}
.oauth-dot{width:18px;height:18px;border-radius:50%;color:#fff;font-size:11px;font-weight:600;display:inline-flex;align-items:center;justify-content:center}
.dev-row{transition:opacity .2s}
</style>

<script>
var Security = (function(){
  function rowOf(sid){ return document.querySelector('#deviceTable [data-sid="' + sid + '"]'); }
  function decCount(){
    var c = document.getElementById('deviceCount');
    c.textContent = Math.max(0, parseInt(c.textContent || '0', 10) - 1);
  }
  function maybeEmpty(){
    var t = document.getElementById('deviceTable');
    if (t && !t.querySelector('[data-sid]')) {
      t.style.display = 'none';
      var e = document.getElementById('deviceEmpty'); if (e) e.style.display = 'block';
    }
  }
  return {
    kick: function(sid){
      if (!confirm('确定要将该设备踢下线吗？')) return;
      var row = rowOf(sid);
      App.req('user','device_kill',{sid:sid},{msg:'已踢下线'}).then(function(res){
        if (res.code !== 0) return;
        if (row) { row.style.opacity = '0'; setTimeout(function(){ row.remove(); decCount(); maybeEmpty(); }, 220); }
      });
    },
    kickAll: function(){
      if (!confirm('将退出该账号在其他所有设备的登录状态（不含当前设备），确定吗？')) return;
      App.req('user','logoutall',{},{msg:'已退出其他设备'}).then(function(res){
        if (res.code !== 0) return;
        var rows = document.querySelectorAll('#deviceTable [data-sid]');
        var n = 0;
        rows.forEach(function(r){
          if (r.classList.contains('row-current')) return;  // 保留当前设备
          r.style.opacity = '0'; n++;
          setTimeout(function(){ r.remove(); }, 220);
        });
        setTimeout(function(){
          var c = document.getElementById('deviceCount');
          c.textContent = Math.max(1, parseInt(c.textContent || '0', 10) - n);
          maybeEmpty();
        }, 260);
      });
    }
  };
})();

// ---------- 密码：明文切换 / 强度 / 一致性 ----------
(function(){
  document.querySelectorAll('.pw-eye').forEach(function(btn){
    btn.addEventListener('click', function(){
      var t = document.getElementById(this.getAttribute('data-target'));
      t.type = (t.type === 'password') ? 'text' : 'password';
      this.style.opacity = (t.type === 'text') ? '1' : '.55';
    });
  });

  var npwd = document.getElementById('npwd');
  var npwd2 = document.getElementById('npwd2');
  var MIN = 6, MAX = 32;

  function strength(p){
    if (!p) return 0;
    var cls = 0;
    if (/[a-z]/.test(p)) cls++;
    if (/[A-Z]/.test(p)) cls++;
    if (/\d/.test(p)) cls++;
    if (/[^A-Za-z0-9]/.test(p)) cls++;
    if (p.length < MIN) return 1;
    if (p.length >= 10 && cls >= 3) return 3;
    if (p.length >= 8 && cls >= 2) return 3;
    if (cls >= 2) return 2;
    if (p.length >= 8) return 2;
    return 1;
  }

  var LABEL = {0:'未输入', 1:'弱', 2:'中', 3:'强'};
  var COLOR = {0:'', 1:'lv1', 2:'lv2', 3:'lv3'};
  var TEXT  = {0:'', 1:'#dc2626', 2:'#f59e0b', 3:'#16a34a'};

  function renderStrength(){
    var p = npwd.value, s = strength(p), hint = document.getElementById('pwHint');
    for (var i = 1; i <= 3; i++) {
      var b = document.getElementById('pwBar' + i);
      b.className = 'pw-bar ' + (i <= s ? COLOR[s] : '');
    }
    var lb = document.getElementById('pwLabel');
    lb.textContent = LABEL[s]; lb.style.color = TEXT[s] || '';

    if (!p) { hint.textContent = '建议 8 位以上，混合大小写字母、数字与符号'; hint.className = 'hint'; return; }
    if (p.length < MIN) { hint.textContent = '密码至少 ' + MIN + ' 位，当前 ' + p.length + ' 位'; hint.className = 'hint pw-hint-err'; return; }
    if (p.length > MAX) { hint.textContent = '密码最多 ' + MAX + ' 位，当前 ' + p.length + ' 位'; hint.className = 'hint pw-hint-err'; return; }
    if (s === 1) { hint.textContent = '强度偏弱：建议加入字母、数字与符号组合'; hint.className = 'hint pw-hint-err'; return; }
    hint.textContent = '长度 ' + p.length + ' 位，符合要求'; hint.className = 'hint';
  }

  function renderMatch(){
    var t = document.getElementById('pwMatch'), a = npwd.value, b = npwd2.value;
    if (!b) { t.className = 'pf-tip'; t.textContent = ''; return true; }
    if (a !== b) { t.className = 'pf-tip err'; t.textContent = '两次输入不一致'; return false; }
    t.className = 'pf-tip ok'; t.textContent = '两次输入一致';
    return true;
  }

  npwd.addEventListener('input', function(){ renderStrength(); renderMatch(); });
  npwd2.addEventListener('input', renderMatch);

  document.getElementById('pwdForm').addEventListener('submit', function(ev){
    ev.preventDefault();
    var d = { old_password: App.val('#oldpwd'), new_password: App.val('#npwd'), confirm: App.val('#npwd2') };
    if (d.new_password.length < MIN) { App.toast('新密码至少 ' + MIN + ' 位', 'err'); npwd.focus(); return; }
    if (!renderMatch()) { App.toast('两次输入的新密码不一致', 'err'); npwd2.focus(); return; }
    if (d.old_password === d.new_password) { App.toast('新密码不能与旧密码相同', 'err'); return; }
    var btn = this.querySelector('[type=submit]');
    btn.disabled = true; btn.innerHTML = '<span class="spin"></span>提交中';
    App.req('user','password', d, {okMsg:false}).then(function(res){
      btn.disabled = false; btn.innerHTML = '修改密码';
      if (res.code !== 0) { App.toast(res.message, 'err'); return; }
      App.toast('密码修改成功，请重新登录', 'ok');
      setTimeout(function(){ location.href = '<?= e($base) ?>/index.php?c=user&a=login'; }, 900);
    });
  });
})();
</script>

<?php if ($passkeyOn): ?>
<script src="<?= e($base) ?>/assets/passkey.js?v=20261015-pk"></script>
<script>
(function () {
  var DOMAIN_BLOCKED = <?= $pkIssue ? 'true' : 'false' ?>;

  var body = document.getElementById('pkBody');
  var tbl  = document.getElementById('pkTable');
  var emp  = document.getElementById('pkEmpty');

  function fmtRow(item) {
    var tr = document.createElement('tr');
    tr.setAttribute('data-id', item.id);
    var label = item.label || '未命名密钥';
    tr.innerHTML =
      '<td>' + escapeHtml(label) + (item.backup_state ? ' <span class="badge badge-ok">已同步</span>' : '') + '</td>' +
      '<td class="nowrap faint">' + escapeHtml(item.created_text) + '</td>' +
      '<td class="nowrap faint">' + escapeHtml(item.last_used_text) + '</td>' +
      '<td class="right">' +
        '<button class="btn btn-s" data-act="rename">重命名</button> ' +
        '<button class="btn btn-s btn-d" data-act="del">删除</button>' +
      '</td>';
    return tr;
  }

  function escapeHtml(s) {
    var d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }

  function render(items) {
    body.innerHTML = '';
    if (!items || !items.length) {
      tbl.style.display = 'none';
      emp.style.display = '';
      return;
    }
    emp.style.display = 'none';
    tbl.style.display = '';
    items.forEach(function (item) { body.appendChild(fmtRow(item)); });
  }

  function loadList() {
    Passkey.list().then(function (res) {
      if (res.code === 0) render(res.data.items || []);
    });
  }

  /* 浏览器不支持 / 当前访问域不可用：禁用添加，但已有密钥仍可查看与删除 */
  if (!Passkey.isSupported()) {
    if (!DOMAIN_BLOCKED) {   // 域不可用时页面已由服务端给出说明，避免「浏览器不支持」误导
      var u = document.getElementById('pkUnsupported');
      if (u) u.style.display = '';
    }
    var ab = document.getElementById('pkAddBtn');
    if (ab) ab.disabled = true;
    loadList();
    return;
  }

  // 添加通行密钥
  document.getElementById('pkAddBtn').addEventListener('click', function () {
    var btn = this;
    var label = prompt('为该通行密钥起个名字（如 MacBook、iPhone）：', '');
    if (label === null) return;
    var old = btn.innerHTML;
    btn.disabled = true; btn.innerHTML = '<span class="spin"></span>验证中';
    Passkey.register({ label: label || '' }).then(function (res) {
      btn.disabled = false; btn.innerHTML = old;
      if (res.code === 0) {
        App.toast('通行密钥已添加', 'ok');
        loadList();
      } else {
        App.toast(res.message || '添加失败', 'err');
      }
    }).catch(function (err) {
      btn.disabled = false; btn.innerHTML = old;
      if (err && err.message && err.message.indexOf('cancel') < 0) {
        App.toast(err.message, 'err');
      }
    });
  });

  // 重命名 / 删除
  body.addEventListener('click', function (ev) {
    var btn = ev.target;
    if (!btn.getAttribute) return;
    var act = btn.getAttribute('data-act');
    var tr = btn.closest('tr');
    if (!tr) return;
    var id = parseInt(tr.getAttribute('data-id'), 10);

    if (act === 'rename') {
      var cur = tr.children[0].textContent.trim().split(/\s/)[0];
      var label = prompt('新名称：', cur);
      if (label === null) return;
      Passkey.rename(id, label).then(function (res) {
        if (res.code === 0) { App.toast('已重命名', 'ok'); loadList(); }
        else App.toast(res.message || '重命名失败', 'err');
      });
    } else if (act === 'del') {
      if (!confirm('确定要删除该通行密钥吗？删除后将无法使用该设备快速登录。')) return;
      Passkey.delete(id).then(function (res) {
        if (res.code === 0) {
          App.toast('已删除', 'ok');
          tr.style.opacity = '0';
          setTimeout(function () { tr.remove(); loadList(); }, 220);
        } else {
          App.toast(res.message || '删除失败', 'err');
        }
      });
    }
  });

  loadList();
})();
</script>
<?php endif; ?>
