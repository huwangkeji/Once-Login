<?php extract($v);
$s    = $v['stats'];
$ping = $v['ping'];

/** 数据概览图标 */
$si = function ($path) {
    return '<span class="stat-ico"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
        . $path . '</svg></span>';
};
$health = [
    'UCenter' => $ping['ok'] ? ['正常', 'ok'] : ['异常', 'err'],
    'API 通信' => ['正常', 'ok'],
    '数据库'  => ['正常', 'ok'],
];
?>
<div class="cs-section">
  <div class="cs-section-h">
    <div>
      <div class="cs-section-t">系统状态</div>
      <div class="cs-section-s">核心依赖连通性实时概览</div>
    </div>
    <div class="card-tools">
      <a class="btn btn-s" href="javascript:;" onclick="location.reload()">刷新</a>
      <?php if (Rbac::can($admin, 'manage:ucenter')): ?>
        <a class="btn btn-s" href="<?= e($base) ?>/admin.php?c=ucenter&amp;check=1">一键体检</a>
      <?php endif; ?>
    </div>
  </div>
  <div class="card">
    <div class="card-b">
      <div class="row" style="gap:8px;flex-wrap:wrap">
        <?php foreach ($health as $k => $h): ?>
          <span class="badge badge-<?= e($h[1]) ?>"><?= e($k) ?>：<?= e($h[0]) ?></span>
        <?php endforeach; ?>
        <span class="badge badge-info">驱动：<?= e(Config::get('uc_driver')) ?></span>
      </div>
      <div class="faint" style="font-size:12.5px;margin-top:8px"><?= e($ping['message']) ?></div>
    </div>
  </div>
</div>

<div class="cs-section">
  <div class="cs-section-h">
    <div>
      <div class="cs-section-t">数据概览</div>
      <div class="cs-section-s">用户、接入与运行关键指标</div>
    </div>
  </div>
  <div class="cs-grid cs-g4">
    <div class="stat has-ico">
      <?= $si('<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>') ?>
      <div class="stat-b">
        <div class="stat-v"><?= intval($s['users']) ?></div>
        <div class="stat-l">用户总数</div>
        <div class="stat-sub">本地资料档案</div>
      </div>
    </div>
    <div class="stat has-ico ok">
      <?= $si('<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/>') ?>
      <div class="stat-b">
        <div class="stat-v"><?= intval($s['today_reg']) ?></div>
        <div class="stat-l">今日注册</div>
        <div class="stat-sub">自然日累计</div>
      </div>
    </div>
    <div class="stat has-ico">
      <?= $si('<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="M10 17l5-5-5-5"/><path d="M15 12H3"/>') ?>
      <div class="stat-b">
        <div class="stat-v"><?= intval($s['today_log']) ?></div>
        <div class="stat-l">今日登录</div>
        <div class="stat-sub">含 SSO 登录</div>
      </div>
    </div>
    <div class="stat has-ico">
      <?= $si('<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/>') ?>
      <div class="stat-b">
        <div class="stat-v"><?= intval($s['sessions']) ?></div>
        <div class="stat-l">在线会话</div>
        <div class="stat-sub">未撤销且未过期</div>
      </div>
    </div>
    <div class="stat has-ico">
      <?= $si('<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 9h6v6H9z"/>') ?>
      <div class="stat-b">
        <div class="stat-v"><?= intval($s['apps']) ?></div>
        <div class="stat-l">接入应用</div>
        <div class="stat-sub">已创建应用数</div>
      </div>
    </div>
    <div class="stat has-ico">
      <?= $si('<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>') ?>
      <div class="stat-b">
        <div class="stat-v"><?= intval($s['tokens']) ?></div>
        <div class="stat-l">有效 Token</div>
        <div class="stat-sub">可用于资源接口</div>
      </div>
    </div>
    <div class="stat has-ico">
      <?= $si('<path d="M4 4h16v16H4z"/><path d="M8 8h8M8 12h8M8 16h5"/>') ?>
      <div class="stat-b">
        <div class="stat-v"><?= intval($s['logs']) ?></div>
        <div class="stat-l">日志总量</div>
        <div class="stat-sub">全类型累计</div>
      </div>
    </div>
    <div class="stat has-ico">
      <?= $si('<path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7L12 5"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7L12 19"/>') ?>
      <div class="stat-b">
        <div class="stat-v"><?= intval($s['ucalls']) ?></div>
        <div class="stat-l">UCenter 调用</div>
        <div class="stat-sub">累计通信次数</div>
      </div>
    </div>
  </div>
</div>

<?php if (Rbac::can($admin, 'view:audit')): ?>
<div class="cs-section">
  <div class="cs-section-h">
    <div>
      <div class="cs-section-t">安全概览</div>
      <div class="cs-section-s">今日风控与管理员安全态势</div>
    </div>
    <div class="card-tools">
      <a class="btn btn-s" href="<?= e($base) ?>/admin.php?c=audit">进入安全审计</a>
    </div>
  </div>
  <div class="cs-grid cs-g4">
    <div class="stat has-ico <?= intval($s['fail_today']) > 0 ? 'warn' : 'ok' ?>">
      <?= $si('<path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/>') ?>
      <div class="stat-b">
        <div class="stat-v"><?= intval($s['fail_today']) ?></div>
        <div class="stat-l">今日登录失败</div>
      </div>
    </div>
    <div class="stat has-ico <?= intval($s['locked_now']) > 0 ? 'err' : '' ?>">
      <?= $si('<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>') ?>
      <div class="stat-b">
        <div class="stat-v"><?= intval($s['locked_now']) ?></div>
        <div class="stat-l">当前锁定账号</div>
      </div>
    </div>
    <div class="stat has-ico">
      <?= $si('<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.9"/>') ?>
      <div class="stat-b">
        <div class="stat-v"><?= intval($s['admins']) ?></div>
        <div class="stat-l">启用管理员</div>
      </div>
    </div>
    <div class="stat has-ico ok">
      <?= $si('<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>') ?>
      <div class="stat-b">
        <div class="stat-v"><?= intval($s['totp_users']) ?></div>
        <div class="stat-l">已开启 TOTP</div>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="cs-section">
  <div class="cs-section-h">
    <div>
      <div class="cs-section-t">快捷入口</div>
      <div class="cs-section-s">高频运维操作直达</div>
    </div>
  </div>
  <div class="card">
    <div class="card-b">
      <div class="row" style="flex-wrap:wrap">
        <?php if (Rbac::can($admin, 'view:apps')): ?>
          <a class="btn" href="<?= e($base) ?>/admin.php?c=apps">新增 / 管理应用</a>
        <?php endif; ?>
        <?php if (Rbac::can($admin, 'view:users')): ?>
          <a class="btn" href="<?= e($base) ?>/admin.php?c=users">用户管理</a>
        <?php endif; ?>
        <?php if (Rbac::can($admin, 'view:sessions')): ?>
          <a class="btn" href="<?= e($base) ?>/admin.php?c=sessions">会话管理</a>
        <?php endif; ?>
        <?php if (Rbac::can($admin, 'view:logs')): ?>
          <a class="btn" href="<?= e($base) ?>/admin.php?c=logs&amp;type=all">查看全部日志</a>
        <?php endif; ?>
        <a class="btn" href="<?= e($base) ?>/admin.php?c=security">安全中心</a>
      </div>
      <div class="divider"></div>
      <div class="muted" style="font-size:12.5px">
        数据分层：账号核心数据（UID / 用户名 / 密码 / 邮箱）保存在 <b>UCenter</b>；
        本地 <b>MySQL</b> 仅保存应用配置、Ticket / Token / Session、扩展资料、日志与系统设置。
      </div>
    </div>
  </div>
</div>
