<?php extract($v); $u = $v['user']; ?>
<div class="mc-page">
  <!-- 页头：标题 + 一句话说明（层次起点） -->
  <div class="mc-ph">
    <div>
      <h2 class="mc-page__title">个人资料</h2>
      <p class="mc-ph__desc">完善资料有助于账号找回与安全校验；用户名等核心字段随 UCenter 统一同步。</p>
    </div>
  </div>

  <!-- 身份概览：头像 / 昵称 / UID / 绑定状态，突出账号主体 -->
  <section class="mc-hero">
    <img id="heroAvatar" class="mc-hero__ava" src="<?= e($u['avatar']) ?>" alt="当前头像">
    <div class="mc-hero__meta">
      <div class="mc-hero__name">
        <b id="heroNick"><?= e($u['nickname']) ?></b>
        <span class="badge badge-info mono">UID <?= intval($u['uid']) ?></span>
      </div>
      <div class="mc-hero__sub">
        <span>邮箱 <span id="heroEmail" class="mc-bind<?= $u['email'] ? '' : ' off' ?>"><?= $u['email'] ? '已绑定' : '未绑定' ?></span></span>
        <span>手机 <span id="heroMobile" class="mc-bind<?= $u['mobile'] ? '' : ' off' ?>"><?= $u['mobile'] ? '已绑定' : '未绑定' ?></span></span>
        <span>注册于 <?= e($u['register_text']) ?></span>
      </div>
    </div>
    <div class="mc-hero__acts">
      <button class="mc-act mc-act--p" type="button" data-edit="nickname">编辑资料</button>
    </div>
  </section>

  <!-- 分组一：基础资料 -->
  <div class="mc-group">
    <div class="mc-group__title">基础资料</div>

    <div class="mc-row">
      <span class="mc-row__icon"><img src="<?= e($u['avatar']) ?>" alt=""></span>
      <div class="mc-row__body">
        <div class="mc-row__label">头像</div>
        <div class="mc-row__value">支持自定义图片地址，留空使用默认头像</div>
      </div>
      <button class="mc-act" type="button" data-edit="avatar">更改</button>
    </div>

    <div class="mc-row">
      <span class="mc-row__icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></span>
      <div class="mc-row__body">
        <div class="mc-row__label">用户 ID</div>
        <div class="mc-row__value mono" id="rv_uid"><?= intval($u['uid']) ?></div>
      </div>
      <button class="mc-act" type="button" id="btnCopyUid">复制</button>
    </div>

    <div class="mc-row">
      <span class="mc-row__icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></span>
      <div class="mc-row__body">
        <div class="mc-row__label">昵称</div>
        <div class="mc-row__value" id="rv_nickname"><?= e($u['nickname']) ?></div>
      </div>
      <button class="mc-act" type="button" data-edit="nickname">更改</button>
    </div>
  </div>

  <!-- 分组二：联系方式 -->
  <div class="mc-group">
    <div class="mc-group__title">联系方式</div>

    <div class="mc-row">
      <span class="mc-row__icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M2 6l10 7 10-7"/></svg></span>
      <div class="mc-row__body">
        <div class="mc-row__label">电子邮箱</div>
        <div class="mc-row__value" id="rv_email"><?= $u['email'] ? e($u['email']) : '未绑定' ?></div>
      </div>
      <button class="mc-act" type="button" data-edit="email">更改</button>
    </div>

    <div class="mc-row">
      <span class="mc-row__icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg></span>
      <div class="mc-row__body">
        <div class="mc-row__label">手机号</div>
        <div class="mc-row__value" id="rv_mobile"><?= $u['mobile'] ? e($u['mobile']) : '未绑定' ?></div>
      </div>
      <button class="mc-act" type="button" data-edit="mobile">更改</button>
    </div>
  </div>

  <!-- 分组三：账号安全 -->
  <div class="mc-group">
    <div class="mc-group__title">账号安全</div>

    <div class="mc-row">
      <span class="mc-row__icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></span>
      <div class="mc-row__body">
        <div class="mc-row__label">密码</div>
        <div class="mc-row__desc">定期更换密码，账号更安全</div>
      </div>
      <a class="mc-act" href="<?= e($base) ?>/index.php?c=user&amp;a=security">更改</a>
    </div>
  </div>

  <!-- 分组四：更多信息 -->
  <div class="mc-group">
    <div class="mc-group__title">更多信息</div>

    <div class="mc-row">
      <span class="mc-row__icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg></span>
      <div class="mc-row__body">
        <div class="mc-row__label">性别</div>
        <div class="mc-row__value" id="rv_gender"><?= e($u['gender_text']) ?></div>
      </div>
      <button class="mc-act" type="button" data-edit="gender">更改</button>
    </div>

    <div class="mc-row">
      <span class="mc-row__icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></span>
      <div class="mc-row__body">
        <div class="mc-row__label">生日</div>
        <div class="mc-row__value" id="rv_birthday"><?= $u['birthday'] ? e($u['birthday']) : '未设置' ?></div>
      </div>
      <button class="mc-act" type="button" data-edit="birthday">更改</button>
    </div>

    <div class="mc-row">
      <span class="mc-row__icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></span>
      <div class="mc-row__body">
        <div class="mc-row__label">个人简介</div>
        <div class="mc-row__value" id="rv_bio"><?= $u['bio'] ? e($u['bio']) : '未填写' ?></div>
      </div>
      <button class="mc-act" type="button" data-edit="bio">更改</button>
    </div>

    <div class="mc-row">
      <span class="mc-row__icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></span>
      <div class="mc-row__body">
        <div class="mc-row__label">注册时间</div>
        <div class="mc-row__value"><?= e($u['register_text']) ?></div>
      </div>
    </div>
  </div>
</div>

<!-- 编辑资料弹窗 -->
<div class="mcm" id="editModal">
  <div class="mcm__bg" data-close="1"></div>
  <div class="mcm__box">
    <div class="mcm__h">
      <span>编辑资料 <span class="mc-faint" style="font-size:12px;font-weight:400">核心字段随 UCenter 同步</span></span>
      <button class="mcm__x" type="button" data-close="1">×</button>
    </div>
    <div class="mcm__b">
      <form id="profileForm">
        <!-- 头像：左侧预览 + 右侧输入 -->
        <div class="pf-avatar">
          <img id="avatarPreview" class="avatar-l" src="<?= e($u['avatar']) ?>" alt="头像预览">
          <div style="flex:1;min-width:0">
            <label class="label" for="avatar">头像地址</label>
            <input class="input" id="avatar" value="<?= strpos($u['avatar'], 'data:') === 0 ? '' : e($u['avatar']) ?>" placeholder="填写图片 URL，留空使用默认头像">
            <div class="hint" id="avatarHint">输入合法图片地址后即时预览；留空则回退到默认头像。</div>
          </div>
        </div>

        <div class="field">
          <label class="label" for="nickname">昵称</label>
          <input class="input" id="nickname" value="<?= e($u['nickname']) ?>" maxlength="30" placeholder="展示给他人看到的名称">
          <div class="hint" id="nicknameHint"><span class="faint">0</span>/30</div>
        </div>

        <div class="field">
          <label class="label" for="email">邮箱</label>
          <input class="input" id="email" type="email" value="<?= e($u['email']) ?>" placeholder="修改后会同步到 UCenter">
          <div class="hint">当前状态：<span id="emailBound"><?= $u['email_bound'] ? '<span class="badge badge-ok">已绑定</span>' : '<span class="badge badge-mute">未绑定</span>' ?></span>
            <span id="emailTip" class="pf-tip"></span>
          </div>
        </div>

        <div class="field">
          <label class="label" for="mobile">手机号</label>
          <input class="input" id="mobile" value="<?= e($u['mobile']) ?>" placeholder="绑定后可用于登录" maxlength="11">
          <div class="hint" id="mobileHint"><span id="mobileTip" class="pf-tip"></span>绑定后可用于登录</div>
        </div>

        <div class="grid g2">
          <div class="field">
            <label class="label" for="gender">性别</label>
            <select class="input" id="gender">
              <?php foreach ([0 => '保密', 1 => '男', 2 => '女'] as $k => $t): ?>
                <option value="<?= $k ?>" <?= intval($u['gender']) === $k ? 'selected' : '' ?>><?= e($t) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label class="label" for="birthday">生日</label>
            <input class="input" id="birthday" type="date" value="<?= e($u['birthday']) ?>">
          </div>
        </div>

        <div class="field">
          <label class="label" for="bio">个人简介</label>
          <textarea class="input" id="bio" maxlength="180" placeholder="介绍一下自己"><?= e($u['bio']) ?></textarea>
          <div class="hint"><span id="bioCount" class="faint">0</span>/180 字</div>
        </div>
      </form>
      <div class="muted" style="font-size:12px">
        用户名由 UCenter 管理（暂不支持自助修改）；邮箱调用 UCenter <code>user.edit</code> 更新；
        其余扩展资料保存在本地并通过 SSO 统一下发。
      </div>
    </div>
    <div class="mcm__f">
      <button class="btn" type="button" data-close="1">取消</button>
      <button class="btn btn-p" type="submit" form="profileForm">保存</button>
    </div>
  </div>
</div>

<style>
.pf-avatar{display:flex;gap:14px;align-items:flex-start;margin-bottom:14px}
.avatar-l{width:56px;height:56px;border-radius:50%;object-fit:cover;border:1px solid #e3e6ea;background:#f4f6f9;flex:none}
@media(max-width:480px){.pf-avatar{flex-direction:column}.avatar-l{width:52px;height:52px}}
.pf-tip{margin-left:6px}
.pf-tip.ok{color:#16a34a}
.pf-tip.err{color:#dc2626}
</style>

<script>
(function(){
  var DEFAULT_AVATAR = <?= json_encode($u['avatar'], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
  var GENDER_TEXT = ['保密', '男', '女'];
  var els = {
    avatar:   document.getElementById('avatar'),
    preview:  document.getElementById('avatarPreview'),
    nickname: document.getElementById('nickname'),
    email:    document.getElementById('email'),
    mobile:   document.getElementById('mobile'),
    gender:   document.getElementById('gender'),
    birthday: document.getElementById('birthday'),
    bio:      document.getElementById('bio')
  };
  var FIELDS = ['avatar','nickname','email','mobile','gender','birthday','bio'];

  /* ---------- 弹窗开关（任一「更改」按钮打开，可聚焦指定字段） ---------- */
  var modal = document.getElementById('editModal');
  function openModal(focusKey){
    modal.classList.add('on');
    document.body.style.overflow = 'hidden';
    if (focusKey && els[focusKey]) setTimeout(function(){ els[focusKey].focus(); }, 60);
  }
  function closeModal(){
    modal.classList.remove('on');
    document.body.style.overflow = '';
  }
  Array.prototype.forEach.call(document.querySelectorAll('[data-edit]'), function(btn){
    btn.addEventListener('click', function(){ openModal(this.getAttribute('data-edit')); });
  });
  Array.prototype.forEach.call(modal.querySelectorAll('[data-close]'), function(el){
    el.addEventListener('click', closeModal);
  });
  document.addEventListener('keydown', function(ev){
    if (ev.key === 'Escape' && modal.classList.contains('on')) closeModal();
  });

  /* ---------- 复制用户 ID ---------- */
  document.getElementById('btnCopyUid').addEventListener('click', function(){
    var uidEl = document.getElementById('rv_uid') || document.querySelector('.mc-row__value.mono');
    var text = uidEl ? uidEl.textContent.trim() : '';
    function done(){ App.toast('已复制', 'ok'); }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done);
    } else {
      var ta = document.createElement('textarea');
      ta.value = text; document.body.appendChild(ta); ta.select();
      document.execCommand('copy'); document.body.removeChild(ta); done();
    }
  });

  /* ---------- 原始值快照（脏数据检测） ---------- */
  var snapshot = {};
  function snapshotNow(){ FIELDS.forEach(function(k){ snapshot[k] = els[k].value; }); }
  function isDirty(){ return FIELDS.some(function(k){ return els[k].value !== snapshot[k]; }); }
  snapshotNow();

  window.addEventListener('beforeunload', function(ev){
    if (!isDirty()) return;
    ev.preventDefault();
    ev.returnValue = '';
  });

  /* ---------- 头像实时预览 ---------- */
  function renderAvatar(url){
    var v = (url || '').trim();
    els.preview.src = v ? v : DEFAULT_AVATAR;
    var hint = document.getElementById('avatarHint');
    if (!v) {
      hint.innerHTML = '当前使用默认头像（留空）。';
      return;
    }
    els.preview.onerror = function(){
      els.preview.src = DEFAULT_AVATAR;
      hint.innerHTML = '<span class="pf-tip err">图片地址无法加载，已回退默认头像</span>';
    };
    els.preview.onload = function(){
      hint.innerHTML = '<span class="pf-tip ok">预览正常</span>';
    };
  }
  els.avatar.addEventListener('input', function(){ renderAvatar(this.value); });
  renderAvatar(els.avatar.value);

  /* ---------- 昵称 / 简介 计数器 ---------- */
  function countNickname(){
    var n = els.nickname.value.length;
    document.querySelector('#nicknameHint').innerHTML = '<span class="faint">' + n + '</span>/30';
  }
  function countBio(){
    document.getElementById('bioCount').textContent = els.bio.value.length;
  }
  els.nickname.addEventListener('input', countNickname);
  els.bio.addEventListener('input', countBio);
  countNickname(); countBio();

  /* ---------- 邮箱 / 手机号 即时校验 ---------- */
  var RE_EMAIL  = /^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/;
  var RE_MOBILE = /^1[3-9]\d{9}$/;
  function tip(el, text, ok){
    el.className = 'pf-tip ' + (ok ? 'ok' : 'err');
    el.textContent = text;
  }
  function checkEmail(){
    var v = els.email.value.trim(), t = document.getElementById('emailTip');
    if (!v) { t.className = 'pf-tip'; t.textContent = ''; return true; }
    if (!RE_EMAIL.test(v)) { tip(t, '邮箱格式不正确', false); return false; }
    tip(t, '格式正确', true);
    return true;
  }
  function checkMobile(){
    var v = els.mobile.value.trim(), t = document.getElementById('mobileTip');
    if (!v) { t.className = 'pf-tip'; t.textContent = ''; return true; }
    if (!RE_MOBILE.test(v)) { tip(t, '手机号格式不正确', false); return false; }
    tip(t, '格式正确', true);
    return true;
  }
  els.email.addEventListener('input', checkEmail);
  els.mobile.addEventListener('input', checkMobile);

  /* ---------- 行值同步 ---------- */
  function rowVal(key, html){
    var el = document.getElementById('rv_' + key);
    if (el) el.textContent = html;
  }
  /* 身份概览里的绑定状态同步 */
  function setBind(el, bound){
    if (!el) return;
    el.textContent = bound ? '已绑定' : '未绑定';
    el.className = 'mc-bind' + (bound ? '' : ' off');
  }

  /* ---------- 提交 ---------- */
  document.getElementById('profileForm').addEventListener('submit', function(ev){
    ev.preventDefault();
    if (!checkEmail())  { App.toast('请修正邮箱格式', 'err'); els.email.focus(); return; }
    if (!checkMobile()) { App.toast('请修正手机号格式', 'err'); els.mobile.focus(); return; }
    if (!els.nickname.value.trim()) { App.toast('昵称不能为空', 'err'); els.nickname.focus(); return; }

    var d = {
      nickname: els.nickname.value.trim(),
      email:    els.email.value.trim(),
      mobile:   els.mobile.value.trim(),
      gender:   els.gender.value,
      birthday: els.birthday.value,
      bio:      els.bio.value,
      avatar:   els.avatar.value.trim()
    };
    var btn = this.querySelector('[type=submit]') || document.querySelector('.mcm__f [type=submit]');
    btn.disabled = true; btn.innerHTML = '<span class="spin"></span>保存中';
    App.req('user','profile', d, {okMsg:false}).then(function(res){
      btn.disabled = false; btn.innerHTML = '保存';
      if (res.code !== 0) { App.toast(res.message, 'err'); return; }
      App.toast('资料已更新', 'ok');
      snapshotNow();                       // 重置脏状态
      closeModal();
      /* 同步行值 */
      rowVal('nickname', d.nickname);
      rowVal('email',    d.email || '未绑定');
      rowVal('mobile',   d.mobile || '未绑定');
      rowVal('gender',   GENDER_TEXT[parseInt(d.gender, 10)] || '保密');
      rowVal('birthday', d.birthday || '未设置');
      rowVal('bio',      d.bio || '未填写');
      /* 同步顶栏 / 身份概览 / 头像 */
      var nick = document.getElementById('mhNick');
      if (nick) nick.textContent = d.nickname;
      var hNick = document.getElementById('heroNick');
      if (hNick) hNick.textContent = d.nickname;
      var avatarUrl = d.avatar || DEFAULT_AVATAR;
      var rowImg = document.querySelector('.mc-row__icon img');
      if (rowImg) rowImg.src = avatarUrl;
      var hAva = document.getElementById('heroAvatar');
      if (hAva) hAva.src = avatarUrl;
      els.preview.src = avatarUrl;
      setBind(document.getElementById('heroEmail'),  !!d.email);
      setBind(document.getElementById('heroMobile'), !!d.mobile);
      if (d.email) {
        document.getElementById('emailBound').innerHTML = '<span class="badge badge-ok">已绑定</span>';
      }
    });
  });
})();
</script>
