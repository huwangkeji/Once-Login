<?php extract($v); $list = $v['list']; $active = $v['type']; ?>
<div class="card">
  <div class="card-h between">
    <span>日志中心 <span class="faint" style="font-weight:400;font-size:12.5px">共 <?= intval($list['total']) ?> 条</span></span>
    <button class="btn btn-s btn-d" onclick="if(confirm('将清理 90 天前的全部日志，确定吗？')) Admin.req('admin','log_clear',{days:90},{after:function(){location.reload();}})">清理 90 天前日志</button>
  </div>
  <div class="card-b tight">
    <div class="tabs" style="margin:0 16px">
      <?php
      $tabs = [
        'all' => '全部',
        'login' => '登录',
        'logout' => '退出',
        'register' => '注册',
        'password' => '改密/找回',
        'profile' => '改资料',
        'sso' => 'SSO',
        'api' => 'API',
        'ucenter' => 'UC 通信',
        'admin' => '后台',
        'app' => '应用',
      ];
      foreach ($tabs as $k => $t):
      ?>
        <a href="<?= e($base) ?>/admin.php?c=logs&type=<?= $k ?>" class="<?= $active === $k ? 'on' : '' ?>"><?= e($t) ?></a>
      <?php endforeach; ?>
    </div>
    <div class="t-scroll">
      <?php if (empty($list['rows'])): ?><div class="empty">暂无日志</div><?php else: ?>
      <table class="table compact">
        <thead><tr><th>时间</th><th>类型</th><th>级别</th><th>用户</th><th>IP</th><th>摘要</th></tr></thead>
        <tbody>
        <?php foreach ($list['rows'] as $l): ?>
          <tr>
            <td class="nowrap mono faint"><?= date('m-d H:i:s', intval($l['created_at'])) ?></td>
            <td><span class="badge badge-info"><?= e($l['type']) ?></span></td>
            <td><?= $l['level'] === 'error' ? '<span class="badge badge-err">error</span>' : ($l['level'] === 'warn' ? '<span class="badge badge-warn">warn</span>' : '<span class="badge badge-mute">info</span>') ?></td>
            <td><?= $l['username'] ? e($l['username']) : ($l['uid'] ? '#' . intval($l['uid']) : '-') ?></td>
            <td class="mono faint"><?= e($l['ip']) ?></td>
            <td>
              <?= e($l['message']) ?>
              <?php if (!empty($l['target'])): ?><div class="faint" style="font-size:12px">对象：<?= e($l['target']) ?></div><?php endif; ?>
              <?php if (!empty($l['extra'])): ?><div class="faint mono" style="font-size:11.5px;margin-top:2px"><?= e(json_encode($l['extra'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></div><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
    <?php if ($list['total'] > $list['size']): ?>
    <div class="card-b">
      <div class="pager">
        <?php $pages = ceil($list['total'] / $list['size']); for ($i = 1; $i <= $pages; $i++): ?>
          <a class="btn btn-s <?= $list['page'] === $i ? 'btn-p' : '' ?>" href="<?= e($base) ?>/admin.php?c=logs&type=<?= e($active) ?>&page=<?= $i ?>"><?= $i ?></a>
        <?php endfor; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>
