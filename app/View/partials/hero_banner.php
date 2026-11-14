<?php
/**
 * 企业级首屏横幅组件（前台 / 后台共用，由 app/View/partials/hero_banner.php 提供）
 *
 * 视觉：蓝色科技渐变背景图 + HTML 文字覆盖层，前后台视觉统一、专业稳重。
 * 资源：public/images/banner-{480x160,768x256,1024x341,1440x480}.{webp,png}（3:1 画幅）
 * 性能：
 *   - <picture> + srcset 按屏幕宽度选择对应分辨率（WebP 优先，PNG 兜底）
 *   - loading="lazy" / decoding="async" 原生懒加载
 *   - 骨架屏 shimmer 占位 + is-loaded 淡入（单例脚本，多组件不重复注册）
 * 可维护：标题 / 标语 / 徽章 / 按钮由 $v['hero'] 传入，改文案无需重新生成图片。
 *
 * 可选参数（$v['hero']）：
 *   title     string  主标题（默认站点名）
 *   subtitle  string  副标语（默认站点 slogan）
 *   badges    array   徽章数组（如 ['一次登录 · 处处通行', '多因子认证', ...]）
 *   cta       bool    是否显示注册/登录按钮（仅前台首页未登录场景）
 */
$hb      = $v['hero'] ?? [];
$hbTitle = $hb['title']    ?? site_name();
$hbSub   = $hb['subtitle'] ?? (string)Config::get('site_slogan', '一次登录，处处通行');
$hbBadges= isset($hb['badges']) && is_array($hb['badges']) ? $hb['badges'] : [];
$hbCta   = (bool)($hb['cta'] ?? false);
$hbBase  = $base ?? '';
$hbImg   = trim(($hb['img'] ?? ''), '/');
$hbImg   = $hbImg !== '' ? '/' . $hbImg . '/' : '/images/';
?>
<div class="hero-banner" role="banner">
  <div class="hero-banner__media" aria-hidden="true">
    <picture>
      <source type="image/webp"
        srcset="<?= e($hbBase) ?><?= e($hbImg) ?>banner-480x160.webp 480w,
                <?= e($hbBase) ?><?= e($hbImg) ?>banner-768x256.webp 768w,
                <?= e($hbBase) ?><?= e($hbImg) ?>banner-1024x341.webp 1024w,
                <?= e($hbBase) ?><?= e($hbImg) ?>banner-1440x480.webp 1440w"
        sizes="(min-width: 1220px) 1188px, calc(100vw - 32px)">
      <img class="hero-banner__img" loading="lazy" decoding="async"
        src="<?= e($hbBase) ?><?= e($hbImg) ?>banner-1440x480.png"
        srcset="<?= e($hbBase) ?><?= e($hbImg) ?>banner-480x160.png 480w,
                <?= e($hbBase) ?><?= e($hbImg) ?>banner-768x256.png 768w,
                <?= e($hbBase) ?><?= e($hbImg) ?>banner-1024x341.png 1024w,
                <?= e($hbBase) ?><?= e($hbImg) ?>banner-1440x480.png 1440w"
        sizes="(min-width: 1220px) 1188px, calc(100vw - 32px)"
        alt="<?= e($hbTitle) ?>" width="1440" height="480">
    </picture>
  </div>
  <div class="hero-banner__cover" aria-hidden="true"></div>
  <div class="hero-banner__content">
    <h2 class="hero-banner__title"><?= e($hbTitle) ?></h2>
    <?php if ($hbSub !== ''): ?><p class="hero-banner__subtitle"><?= e($hbSub) ?></p><?php endif; ?>
    <?php if ($hbBadges): ?>
    <div class="hero-banner__badges">
      <?php foreach ($hbBadges as $hbB): ?><span class="hero-banner__badge"><?= e($hbB) ?></span><?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php if ($hbCta): ?>
    <div class="hero-banner__cta">
      <a class="btn btn-p" href="<?= e($hbBase) ?>/index.php?c=user&a=register">立即注册</a>
      <a class="btn btn-plain" href="<?= e($hbBase) ?>/index.php?c=user&a=login">已有账号，登录</a>
    </div>
    <?php endif; ?>
  </div>
</div>
<script>
/* 横幅淡入（单例：全页只注册一次；图片加载完成前由骨架屏 shimmer 占位） */
(function () {
  if (window.__heroBannerInit) return;
  window.__heroBannerInit = true;
  function ready() {
    var imgs = document.querySelectorAll('.hero-banner__img');
    for (var i = 0; i < imgs.length; i++) {
      (function (im) {
        if (im.complete && im.naturalWidth > 0) { im.classList.add('is-loaded'); return; }
        im.addEventListener('load', function () { im.classList.add('is-loaded'); });
        im.addEventListener('error', function () { im.classList.add('is-loaded'); });
      })(imgs[i]);
    }
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', ready);
  } else {
    ready();
  }
})();
</script>