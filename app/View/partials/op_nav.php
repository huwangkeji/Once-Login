<?php
/**
 * 开放平台统一顶部导航（前台门户 与 开放文档 docs.php 共用）
 * 保证两处配色、入口与交互完全一致。
 *
 * @param string      $base   站点根地址
 * @param array|null  $user   当前登录用户（未登录传 null）
 * @param string      $active 当前导航键：home / docs / profile / security / login / register
 */
$opBase   = $base ?? '';
$opUser   = $user ?? null;
$opActive = $active ?? 'home';
$on = function ($k) use ($opActive) {
    return $opActive === $k ? 'on' : '';
};
?>
<nav class="nav">
  <div class="nav-in">
    <a class="brand" href="<?= e($opBase) ?>/index.php"><span class="logo">U</span><?= e(site_name()) ?></a>

    <div class="nav-links">
      <a href="<?= e($opBase) ?>/index.php" class="<?= $on('home') ?>">首页</a>
      <a href="<?= e($opBase) ?>/index.php?c=docs" class="<?= $on('docs') ?>">开放文档</a>
      <?php if ($opUser): ?>
        <a href="<?= e($opBase) ?>/index.php?c=user&amp;a=profile" class="<?= $on('profile') ?>">个人资料</a>
        <a href="<?= e($opBase) ?>/index.php?c=user&amp;a=security" class="<?= $on('security') ?>">账号安全</a>
      <?php endif; ?>
    </div>

    <div class="nav-right">
      <span class="op-nav-ver">v<?= e(UIC_VERSION) ?></span>
      <?php if ($opUser): ?>
        <img class="avatar-s" src="<?= e($opUser['avatar']) ?>" alt="">
        <span style="font-size:13.5px"><b><?= e($opUser['nickname']) ?></b>
          <span class="faint mono">#<?= intval($opUser['uid']) ?></span></span>
        <a class="btn btn-s" href="javascript:;" onclick="App.logout()">退出</a>
      <?php else: ?>
        <a class="btn btn-s" href="<?= e($opBase) ?>/index.php?c=user&amp;a=login">登录</a>
        <a class="btn btn-s btn-p" href="<?= e($opBase) ?>/index.php?c=user&amp;a=register">注册</a>
      <?php endif; ?>
      <a class="btn btn-s" href="<?= e($opBase) ?>/admin.php">控制台</a>
    </div>
  </div>
</nav>
