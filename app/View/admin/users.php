<?php extract($v); $data = $v['data']; $kw = $v['kw'] ?? ''; ?>
<div class="card">
  <div class="card-h between">
    <span>用户管理 <span class="faint" style="font-weight:400;font-size:12.5px">共 <?= intval($data['total']) ?> 条 · 核心数据来自 UCenter</span></span>
    <form class="row" style="gap:6px" method="get" action="<?= e($base) ?>/admin.php">
      <input type="hidden" name="c" value="users">
      <input class="input btn-s" style="width:210px" name="kw" value="<?= e($kw) ?>" placeholder="用户名/昵称/手机号">
      <button class="btn btn-s btn-p">搜索</button>
    </form>
  </div>
  <div class="card-b tight t-scroll">
    <?php if (empty($data['rows'])): ?><div class="empty">暂无数据</div><?php else: ?>
    <table class="table">
      <thead><tr>
        <th>UID</th><th>用户名</th><th>昵称</th><th>邮箱</th><th>手机号</th>
        <th>性别</th><th>注册时间</th><th>UC 状态</th><th class="right">操作</th>
      </tr></thead>
      <tbody>
      <?php foreach ($data['rows'] as $u): ?>
        <tr>
          <td class="mono"><?= intval($u['uid']) ?></td>
          <td class="mono"><b><?= e($u['username']) ?></b></td>
          <td><?= e($u['nickname']) ?></td>
          <td><?= e($u['email']) ?></td>
          <td class="mono"><?= e($u['mobile']) ?></td>
          <td><?= e($u['gender_text']) ?></td>
          <td class="nowrap faint"><?= date('m-d H:i', intval($u['created_at'])) ?></td>
          <td><?= $u['uc_ok'] ? '<span class="badge badge-ok">已同步</span>' : '<span class="badge badge-err">未查到</span>' ?></td>
          <td class="right">
            <button class="btn btn-s" onclick="Users.act(<?= intval($u['uid']) ?>,'kick','踢下线')">踢下线</button>
            <button class="btn btn-s btn-d" onclick="Users.act(<?= intval($u['uid']) ?>,'disable','禁用')">禁用</button>
            <button class="btn btn-s" onclick="Users.act(<?= intval($u['uid']) ?>,'enable','恢复')">恢复</button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
  <?php if ($data['total'] > $data['size']): ?>
  <div class="card-b">
    <div class="pager">
      <?php $pages = ceil($data['total'] / $data['size']); for ($i = 1; $i <= $pages; $i++): ?>
        <a class="btn btn-s <?= $data['page'] === $i ? 'btn-p' : '' ?>"
           href="<?= e($base) ?>/admin.php?c=users&page=<?= $i ?>&kw=<?= urlencode($kw) ?>"><?= $i ?></a>
      <?php endfor; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<script>
var Users = {
  act: function(uid, act, label){
    if (!confirm('确定要执行「' + label + '」操作吗？')) return;
    Admin.req('admin','user_action',{uid:uid, act:act},{after:function(){
      setTimeout(function(){ location.reload(); }, 600);
    }});
  }
};
</script>
