<?php
/**
 * 安全验证卡片（前台 / 后台共用，由 app/View/partials/captcha_card.php 提供）
 * 依赖 public/assets/captcha.js（UicCaptcha 模块）与 app.css 中的 .verify-* 样式
 *
 * 调用方 include 前可预设变量：
 *   $capShow(bool)      是否显示验证码（默认取 login_captcha）
 *   $capStyle(string)   默认验证方式（默认取 captcha_style）
 *   $capFallback(string)无感验证回退方式（默认取 captcha_fallback）
 *   $base(string)       站点 base 路径（由布局注入）
 */
$capShow     = isset($capShow) ? (bool)$capShow : (bool)Config::get('login_captcha');
$capStyle    = isset($capStyle) ? (string)$capStyle : (string)Config::get('captcha_style', 'slide');
$capFallback = isset($capFallback) ? (string)$capFallback : (string)Config::get('captcha_fallback', 'slide');
$capBase     = isset($base) ? (string)$base : '';
// 无感验证「跨页无缝放行」：已持有有效信任凭证（HMAC 签名、绑定 IP）则无需再次验证
$capTrusted = false;
if ($capStyle === 'passive' && $capShow && class_exists('RiskEngine') && RiskEngine::trustValid()) {
    $capShow = false;
    $capTrusted = true;
}
?>
<?php if ($capShow): ?>
<div class="verify-card">
  <!-- 验证结果提示（全部验证方式共用：成功绿 / 失败红，captcha.js 驱动） -->
  <div class="verify-toast" id="capToast" role="status" aria-live="polite"></div>
  <div class="verify-card__header">
    <div class="verify-icon">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
    </div>
    <div class="verify-title">
      <div class="verify-title__main">安全验证</div>
      <div class="verify-title__sub" id="capSubtitle">请完成下方验证，确保登录环境安全</div>
    </div>
  </div>
  <div class="verify-card__body">
    <!-- 字符验证码（digits / mixed） -->
    <div class="verify-mode verify-mode--text hide" id="capModeText">
      <div class="verify-image-box">
        <img id="capImg" src="" alt="验证码" class="verify-image">
        <div class="verify-image-overlay" id="capImgOverlay">
          <div class="verify-loading"><div class="verify-loading__spinner"></div><div class="verify-loading__text">加载中...</div></div>
        </div>
      </div>
      <div class="verify-input-group">
        <input class="input verify-input" id="captcha_answer" placeholder="请输入图中 4 位字符" autocomplete="off" maxlength="6">
      </div>
    </div>
    <!-- 滑动拼图（slide） -->
    <div class="verify-mode verify-mode--slide hide" id="capModeSlide">
      <div class="verify-slide-wrap">
        <div class="verify-image-box verify-image-box--wide">
          <div class="verify-slide-stage" id="capStage">
            <!-- 独立类名 verify-slide-bg：避免被 .auth-cap/.verify-image 等字符图样式连带（历史上曾被压成 46px 高） -->
            <img id="capBg" src="" alt="验证背景" class="verify-slide-bg">
            <img id="capPiece" src="" alt="拼图块" class="verify-slide-piece">
            <div class="verify-image-overlay" id="capBgOverlay">
              <div class="verify-loading"><div class="verify-loading__spinner"></div><div class="verify-loading__text">加载中...</div></div>
            </div>
          </div>
        </div>
        <div class="verify-slider" id="capSlider">
          <div class="verify-slider__track" id="capTrack">
            <div class="verify-slider__progress" id="capFill"></div>
            <div class="verify-slider__thumb" id="capThumb">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 9l4-4 4 4"/><path d="M5 15l4 4 4-4"/></svg>
            </div>
          </div>
          <!-- max 由 JS 按 (背景宽 - 拼图边长) 设定，保证可拖到缺口且不会溢出右边界 -->
          <input type="range" id="capRange" class="verify-slider__input" min="0" max="250" step="1" value="0" aria-label="拖动滑块完成拼图验证">
          <div class="verify-slider__hint" id="capHint">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:4px"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><circle cx="12" cy="12" r="3"/></svg>
            拖动滑块，将拼图对齐缺口位置
          </div>
        </div>
      </div>
    </div>
    <!-- 文字点选 / 图标选择（click / icon） -->
    <div class="verify-mode verify-mode--pick hide" id="capModePick">
      <div class="verify-prompt" id="capPrompt"></div>
      <div class="verify-pick-stage" id="capPickStage">
        <img id="capPickImg" src="" alt="点选验证码" class="verify-pick-image">
        <div class="verify-pick-grid" id="capPickGrid"></div>
        <div class="verify-image-overlay" id="capPickOverlay">
          <div class="verify-loading"><div class="verify-loading__spinner"></div><div class="verify-loading__text">加载中...</div></div>
        </div>
      </div>
    </div>
    <!-- 无感验证（passive） -->
    <div class="verify-mode verify-mode--passive hide" id="capModePassive">
      <div class="verify-passive" id="capPassive">
        <div class="verify-passive__spinner" id="capPassiveSpin"></div>
        <div class="verify-passive__text" id="capPassiveText">正在检测当前环境安全性，请稍候…</div>
      </div>
    </div>
  </div>
  <div class="verify-card__foot">
    <span class="verify-foot__hint" id="capFootHint">看不清？换一张试试</span>
    <button type="button" class="btn btn-ghost verify-refresh" id="capRefresh">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1 2.12-9.36L23 10"/></svg>
      <span>换一张</span>
    </button>
  </div>
</div>
<?php endif; ?>
<script src="<?= e($capBase) ?>/assets/captcha.js?v=20261014d"></script>
<?php if ($capTrusted): ?>
<!-- 已持有无感信任凭证：告知前端跳过卡片渲染与行为采集，直接视为已验证（跨页无缝放行） -->
<script>window.__UIC_CAP_TRUSTED__ = true;</script>
<?php endif; ?>
