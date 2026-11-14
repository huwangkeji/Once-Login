<?php extract($v); ?>
<div class="wrap wrap-narrow narrow-stage">
  <div class="narrow-inner">
  <div class="card">
    <div class="card-h"><?= e($v['title'] ?? '提示') ?></div>
    <div class="card-b">
      <div class="alert alert-warn"><?= nl2br(e($v['msg'] ?? '')) ?></div>
      <a class="btn btn-p btn-block" href="<?= e($base) ?>/index.php">返回首页</a>
    </div>
  </div>
  </div>
</div>
