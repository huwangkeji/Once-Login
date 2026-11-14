<?php extract($v);
/** @var array $page SitePages::get() 返回的单页内容 */
$sections = $page['sections'] ?? [];
$contactEmail = (string)Config::get('site_contact_email', '');
$contactUrl   = (string)Config::get('site_contact_url', '');
$contactPhone = (string)Config::get('site_contact_phone', '');
?>
<div class="wrap">
  <div class="card">
    <div class="card-h between">
      <span><?= e($page['title'] ?? '') ?></span>
      <a class="btn btn-s" href="<?= e($base) ?>/index.php">返回首页</a>
    </div>
    <div class="card-b">
      <?php if (!empty($page['updated'])): ?>
        <div class="faint" style="font-size:12.5px;margin-bottom:14px">更新日期：<?= e($page['updated']) ?></div>
      <?php endif; ?>
      <?php if (!empty($page['intro'])): ?>
        <p class="page-intro"><?= e($page['intro']) ?></p>
      <?php endif; ?>
      <?php foreach ($sections as $sec): ?>
        <div class="page-sec">
          <h3><?= e($sec['h'] ?? '') ?></h3>
          <p><?= e($sec['p'] ?? '') ?></p>
        </div>
      <?php endforeach; ?>

      <?php if (!empty($page['contact'])): ?>
        <div class="page-sec">
          <h3>联系我们</h3>
          <?php if ($contactEmail !== ''): ?>
            <p>反馈邮箱：<a href="mailto:<?= e($contactEmail) ?>"><?= e($contactEmail) ?></a></p>
          <?php endif; ?>
          <?php if ($contactPhone !== ''): ?>
            <p>联系电话：<?= e($contactPhone) ?></p>
          <?php endif; ?>
          <?php if ($contactUrl !== ''): ?>
            <p>在线渠道：<a href="<?= e($contactUrl) ?>" target="_blank" rel="noopener"><?= e($contactUrl) ?></a></p>
          <?php endif; ?>
          <?php if ($contactEmail === '' && $contactUrl === '' && $contactPhone === ''): ?>
            <p class="faint">站点管理员尚未配置对外联系方式。您可以在「设置中心 → 站点信息 → 联系方式」中填写联系邮箱 / 电话 / 在线客服地址后，此处将自动展示。</p>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
