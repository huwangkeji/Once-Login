<?php extract($v); ?>
<div class="wrap wrap-narrow narrow-stage">
  <div class="narrow-inner">
  <div class="center">
    <div class="logo" style="width:64px;height:64px;font-size:28px;margin:0 auto 16px">U</div>
    <div style="font-size:21px;font-weight:700">系统维护中</div>
    <div class="muted" style="font-size:13.5px;margin-top:10px;line-height:1.8">
      系统正在进行升级维护，暂不接受新的访问。<br>
      请稍后再试，给您带来的不便敬请谅解。
    </div>
    <div style="margin-top:20px">
      <a class="btn btn-p" href="javascript:;" onclick="location.reload()">刷新重试</a>
      <a class="btn" href="<?= e($base) ?>/admin.php">管理后台入口</a>
    </div>
  </div>
  </div>
</div>