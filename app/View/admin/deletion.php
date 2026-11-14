<?php extract($v);
$list    = $v['list'];
$counts  = $v['counts'];
$status  = $v['status'];
$recycle = $v['recycle'];
$stMap = ['pending' => ['待审核', 'warn'], 'approved' => ['已通过', 'ok'], 'rejected' => ['已驳回', 'err']];
?>
<div class="cs-section">
  <div class="cs-section-h">
    <div>
      <div class="cs-section-t">注销概况</div>
      <div class="cs-section-s">申请审核与回收站存量</div>
    </div>
    <div class="card-tools">
      <a class="btn btn-s" href="<?= e($base) ?>/admin.php?c=deletion&amp;status=pending">待审核</a>
      <a class="btn btn-s" href="<?= e($base) ?>/admin.php?c=deletion&amp;status=approved">已通过</a>
      <a class="btn btn-s" href="<?= e($base) ?>/admin.php?c=deletion&amp;status=all">全部</a>
    </div>
  </div>
  <div class="cs-grid cs-g4">
    <div class="stat has-ico warn">
      <span class="stat-ico"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/></svg></span>
      <div class="stat-b"><div class="stat-v"><?= intval($counts['pending']) ?></div><div class="stat-l">待审核</div></div>
    </div>
    <div class="stat has-ico ok">
      <span class="stat-ico"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg></span>
      <div class="stat-b"><div class="stat-v"><?= intval($counts['approved']) ?></div><div class="stat-l">已通过</div></div>
    </div>
    <div class="stat has-ico err">
      <span class="stat-ico"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18M6 6l12 12"/></svg></span>
      <div class="stat-b"><div class="stat-v"><?= intval($counts['rejected']) ?></div><div class="stat-l">已驳回</div></div>
    </div>
    <div class="stat has-ico">
      <span class="stat-ico"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/></svg></span>
      <div class="stat-b"><div class="stat-v"><?= intval($counts['recycle']) ?></div><div class="stat-l">回收站</div></div>
    </div>
  </div>
</div>

<div class="cs-section">
  <div class="cs-section-h">
    <div>
      <div class="cs-section-t">注销申请</div>
      <div class="cs-section-s">共 <?= intval($list['total']) ?> 条</div>
    </div>
  </div>
  <div class="card">
    <?php if (empty($list['rows'])): ?>
      <div class="cs-empty">暂无注销申请</div>
    <?php else: ?>
    <div class="t-scroll">
      <table class="table">
        <thead>
          <tr><th>ID</th><th>用户</th><th>注销原因</th><th>状态</th><th>提交时间</th><th>审核人</th><th style="width:180px">操作</th></tr>
        </thead>
        <tbody>
          <?php foreach ($list['rows'] as $r): 
            $st = $stMap[$r['status']] ?? [$r['status'], 'mute'];
          ?>
          <tr>
            <td class="mono"><?= intval($r['id']) ?></td>
            <td>
              <b><?= e($r['username']) ?></b>
              <div class="faint mono" style="font-size:11.5px">#<?= intval($r['uid']) ?></div>
            </td>
            <td style="max-width:280px"><?= e($r['reason'] ?: '（未填写）') ?></td>
            <td><span class="badge badge-<?= e($st[1]) ?>"><?= e($st[0]) ?></span></td>
            <td class="faint" style="font-size:12.5px"><?= e(human_time($r['created_at'])) ?></td>
            <td class="faint" style="font-size:12.5px"><?= e($r['reviewer'] ?: '-') ?></td>
            <td>
              <?php if ($r['status'] === 'pending'): ?>
                <button class="btn btn-s btn-p" type="button" data-act="approve" data-id="<?= intval($r['id']) ?>">通过</button>
                <button class="btn btn-s" type="button" data-act="reject" data-id="<?= intval($r['id']) ?>">驳回</button>
              <?php else: ?>
                <span class="faint" style="font-size:12.5px">已处理</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="cs-section">
  <div class="cs-section-h">
    <div>
      <div class="cs-section-t">账号回收站</div>
      <div class="cs-section-s">审核通过后移入，保留资料快照以便追溯</div>
    </div>
  </div>
  <div class="card">
    <?php if (empty($recycle['rows'])): ?>
      <div class="cs-empty">回收站为空</div>
    <?php else: ?>
    <div class="t-scroll">
      <table class="table">
        <thead><tr><th>UID</th><th>用户名</th><th>邮箱</th><th>手机</th><th>操作人</th><th>注销时间</th></tr></thead>
        <tbody>
          <?php foreach ($recycle['rows'] as $r): ?>
          <tr>
            <td class="mono"><?= intval($r['uid']) ?></td>
            <td><b><?= e($r['username']) ?></b></td>
            <td class="faint" style="font-size:12.5px"><?= e($r['email'] ?: '-') ?></td>
            <td class="faint" style="font-size:12.5px"><?= e($r['mobile'] ?: '-') ?></td>
            <td class="faint" style="font-size:12.5px"><?= e($r['operator']) ?></td>
            <td class="faint" style="font-size:12.5px"><?= e(human_time($r['deleted_at'])) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<script>
(function(){
  var btns = document.querySelectorAll('[data-act]');
  for (var i = 0; i < btns.length; i++) {
    btns[i].addEventListener('click', function(){
      var act = this.getAttribute('data-act');
      var id  = this.getAttribute('data-id');
      var approve = (act === 'approve');
      if (approve && !confirm('通过后该账号将立即停用、全部会话下线，并移入回收站。确认继续？')) return;
      var remark = approve ? '' : (prompt('驳回原因（可选）', '') || '');
      Admin.req('admin','deletion_review', { id: id, approve: approve ? 1 : 0, remark: remark }, {okMsg:false})
        .then(function(res){
          if (res && res.code === 0) { Admin.toast(res.message || '已处理','ok'); setTimeout(function(){ location.reload(); }, 700); }
        });
    });
  }
})();
</script>
