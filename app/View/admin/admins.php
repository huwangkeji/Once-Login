<?php
extract($v);
$list = $v['list'] ?? [];
$me   = $admin;          // 当前登录管理员
$meId = intval($me['id'] ?? 0);
?>
<div class="card">
  <div class="card-h between">
    <span>管理员 <span class="faint" style="font-weight:400;font-size:12.5px">共 <?= count($list) ?> 人</span></span>
    <div>
      <button class="btn btn-s" onclick="AdminM.openPwd()">修改我的密码</button>
      <button class="btn btn-s btn-p" onclick="AdminM.open()">新增管理员</button>
    </div>
  </div>
  <div class="card-b tight t-scroll">
    <?php if (empty($list)): ?><div class="empty">暂无管理员</div><?php else: ?>
    <table class="table compact">
      <thead><tr><th>ID</th><th>账号</th><th>姓名</th><th>邮箱</th><th>角色</th><th>状态</th><th>最近登录</th><th>操作</th></tr></thead>
      <tbody>
      <?php foreach ($list as $a): ?>
        <tr>
          <td class="mono faint"><?= intval($a['id']) ?></td>
          <td><?= e($a['username']) ?><?= intval($a['id']) === $meId ? ' <span class="badge badge-info">我</span>' : '' ?></td>
          <td><?= e($a['realname'] ?: '-') ?></td>
          <td><?= ($a['email'] ?? '') === '' ? '<span class="faint">未设置</span>' : e($a['email']) ?></td>
          <td><span class="badge badge-mute"><?= e($a['role']) ?></span></td>
          <td><?= intval($a['status']) ? '<span class="badge badge-ok">正常</span>' : '<span class="badge badge-err">已禁用</span>' ?></td>
          <td class="nowrap mono faint"><?= $a['last_login_at'] ? human_time(intval($a['last_login_at'])) : '从未' ?><?= $a['last_login_ip'] ? '<div class="faint" style="font-size:11px">' . e($a['last_login_ip']) . '</div>' : '' ?></td>
          <td class="nowrap">
            <button class="btn btn-s" onclick="AdminM.open(<?= intval($a['id']) ?>,<?= e(json_encode($a['realname'] ?? '')) ?>,<?= e(json_encode($a['role'] ?? 'admin')) ?>,<?= e(json_encode($a['username'])) ?>,<?= e(json_encode($a['email'] ?? '')) ?>)">编辑</button>
            <?php if (intval($a['id']) !== 1): ?>
              <button class="btn btn-s btn-w" onclick="AdminM.toggle(<?= intval($a['id']) ?>,<?= intval($a['status']) ? 0 : 1 ?>)"><?= intval($a['status']) ? '禁用' : '启用' ?></button>
              <button class="btn btn-s btn-d" onclick="AdminM.del(<?= intval($a['id']) ?>)">删除</button>
            <?php else: ?>
              <span class="faint" style="font-size:12px">超级管理员</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
  <div class="card-b">
    <div class="hint">提示：ID=1 为系统超级管理员，不可禁用或删除。管理员账号仅用于登录本认证中心后台，认证账号数据保存在 UCenter，互不影响。开启「邮件服务」并为管理员填写邮箱后，该管理员即可在后台登录页通过「忘记密码？」用邮件验证码自助重置密码。</div>
  </div>
</div>

<!-- 新增 / 编辑 弹层 -->
<div class="modal-mask" id="mAdmin" style="display:none">
  <div class="modal">
    <div class="modal-h"><span id="mAdminTitle">新增管理员</span><a class="modal-x" onclick="AdminM.close()">×</a></div>
    <div class="modal-b">
      <input type="hidden" id="adm_id">
      <div class="field" style="margin-bottom:10px">
        <label class="label">账号</label>
        <input class="input" id="adm_username" placeholder="登录账号" autocomplete="off">
      </div>
      <div class="field" style="margin-bottom:10px" id="adm_pwd_field">
        <label class="label">密码</label>
        <input class="input" id="adm_password" type="password" placeholder="新增时必填" autocomplete="off">
      </div>
      <div class="field" style="margin-bottom:10px">
        <label class="label">姓名 / 备注</label>
        <input class="input" id="adm_realname" placeholder="可选">
      </div>
      <div class="field" style="margin-bottom:10px">
        <label class="label">邮箱</label>
        <input class="input" id="adm_email" type="email" placeholder="用于找回密码接收验证码" autocomplete="off">
        <div class="hint" style="margin-top:6px">开启邮件服务且填写邮箱后，该管理员可在后台登录页「忘记密码？」自助重置。</div>
      </div>
      <div class="field" style="margin-bottom:10px">
        <label class="label">角色</label>
        <select class="input" id="adm_role">
          <option value="admin">admin — 管理员</option>
          <option value="operator">operator — 运营</option>
          <option value="auditor">auditor — 审计</option>
        </select>
      </div>
    </div>
    <div class="modal-f">
      <button class="btn" onclick="AdminM.close()">取消</button>
      <button class="btn btn-p" onclick="AdminM.save()">保存</button>
    </div>
  </div>
</div>

<!-- 修改密码 弹层 -->
<div class="modal-mask" id="mPwd" style="display:none">
  <div class="modal">
    <div class="modal-h"><span>修改我的密码</span><a class="modal-x" onclick="AdminM.closePwd()">×</a></div>
    <div class="modal-b">
      <div class="field" style="margin-bottom:10px">
        <label class="label">原密码</label>
        <input class="input" id="pwd_old" type="password" autocomplete="off">
      </div>
      <div class="field" style="margin-bottom:10px">
        <label class="label">新密码</label>
        <input class="input" id="pwd_new" type="password" autocomplete="off">
      </div>
    </div>
    <div class="modal-f">
      <button class="btn" onclick="AdminM.closePwd()">取消</button>
      <button class="btn btn-p" onclick="AdminM.savePwd()">确认修改</button>
    </div>
  </div>
</div>

<script>
var AdminM = {
  open: function(id, realname, role, username, email){
    id = id || 0;
    var isEdit = !!id;
    document.getElementById('mAdminTitle').textContent = isEdit ? '编辑管理员' : '新增管理员';
    document.getElementById('adm_id').value = isEdit ? id : '';
    var uInp = document.getElementById('adm_username');
    if (isEdit) {
      uInp.value = (username || '').replace(/^"|"$/g, '');
      uInp.disabled = true;
      document.getElementById('adm_pwd_field').style.display = 'none';
      document.getElementById('adm_realname').value = (realname || '').replace(/^"|"$/g, '');
      document.getElementById('adm_email').value = (email || '').replace(/^"|"$/g, '');
      document.getElementById('adm_role').value = (role || 'admin').replace(/^"|"$/g, '');
    } else {
      uInp.value = '';
      uInp.disabled = false;
      document.getElementById('adm_pwd_field').style.display = 'block';
      document.getElementById('adm_password').value = '';
      document.getElementById('adm_realname').value = '';
      document.getElementById('adm_email').value = '';
      document.getElementById('adm_role').value = 'admin';
    }
    document.getElementById('mAdmin').style.display = 'flex';
  },
  close: function(){ document.getElementById('mAdmin').style.display='none'; },
  save: function(){
    var id = document.getElementById('adm_id').value;
    var data = {
      username: document.getElementById('adm_username').value.trim(),
      realname: document.getElementById('adm_realname').value.trim(),
      email:    document.getElementById('adm_email').value.trim(),
      role:     document.getElementById('adm_role').value
    };
    if (!id) {
      data.password = document.getElementById('adm_password').value;
      if (!data.username || !data.password) { Admin.toast('账号和密码不能为空', 'err'); return; }
    }
    if (id) data.id = id;
    Admin.req('admin','admin_save', data, { after:function(){ location.reload(); } });
  },
  toggle: function(id, status){
    Admin.req('admin','admin_toggle', { id: id, status: status }, { after:function(){ location.reload(); } });
  },
  del: function(id){
    if (!confirm('确定删除该管理员？此操作不可恢复。')) return;
    Admin.req('admin','admin_delete', { id: id }, { after:function(){ location.reload(); } });
  },
  openPwd: function(){ document.getElementById('mPwd').style.display='flex'; },
  closePwd: function(){ document.getElementById('mPwd').style.display='none'; },
  savePwd: function(){
    var oldp = document.getElementById('pwd_old').value;
    var newp = document.getElementById('pwd_new').value;
    if (!oldp || !newp) { Admin.toast('请填写完整', 'err'); return; }
    Admin.req('admin','admin_password', { old_password: oldp, new_password: newp }, {
      after:function(){ AdminM.closePwd(); }
    });
  }
};
</script>

<style>
.modal-mask{position:fixed;inset:0;background:rgba(20,12,40,.45);display:flex;align-items:center;justify-content:center;z-index:50}
.modal{background:#fff;border-radius:12px;width:380px;max-width:92vw;box-shadow:0 20px 60px rgba(40,20,80,.25);overflow:hidden}
.modal-h{display:flex;justify-content:space-between;align-items:center;padding:14px 16px;border-bottom:1px solid var(--line,#eee);font-weight:600}
.modal-x{cursor:pointer;font-size:20px;color:#999;line-height:1}
.modal-b{padding:16px}
.modal-f{display:flex;justify-content:flex-end;gap:10px;padding:12px 16px;border-top:1px solid var(--line,#eee)}
</style>
