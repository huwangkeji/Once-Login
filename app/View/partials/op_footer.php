<?php
/**
 * 开放平台统一页脚（前台门户 与 开放文档 docs.php 共用）
 * 展示：站点信息、联系方式、ICP / 公安备案号、版权声明与附加 HTML。
 *
 * 若页面尚未定义 App 对象（如独立的 docs.php），此处补一个最小实现，
 * 保证导航中的「退出」等交互在两处行为一致。
 *
 * @param string $base 站点根地址
 */
$opBase = $base ?? '';

$company    = (string)Config::get('site_company', '');
$icp        = (string)Config::get('site_icp', '');
$icpLink    = (string)Config::get('site_icp_link', 'https://beian.miit.gov.cn/');
$police     = (string)Config::get('site_police', '');
$policeLink = (string)Config::get('site_police_link', '');
$email      = (string)Config::get('site_contact_email', '');
$phone      = (string)Config::get('site_contact_phone', '');
$contactUrl = (string)Config::get('site_contact_url', '');
$footerText = (string)Config::get('site_footer_text', '');
$footerHtml = (string)Config::get('site_footer_html', '');
$copyright  = (string)Config::get('site_copyright', '');
if ($copyright === '') {
    $copyright = '© ' . date('Y') . ' ' . ($company !== '' ? $company : site_name());
}
$hasContact = ($email !== '' || $phone !== '' || $contactUrl !== '');
?>
<div class="footer op-footer">
  <div class="op-footer-in">
    <div class="op-footer-brand">
      <span class="logo">U</span>
      <div>
        <div class="op-footer-name"><?= e(site_name()) ?></div>
        <div class="op-footer-slogan"><?= e((string)Config::get('site_slogan', '一次登录，处处通行')) ?></div>
        <?php if ($company !== ''): ?>
          <div class="op-footer-company"><?= e($company) ?></div>
        <?php endif; ?>
      </div>
    </div>

    <div class="op-footer-cols">
      <div class="op-footer-col">
        <div class="op-footer-t">产品</div>
        <a href="<?= e($opBase) ?>/index.php">平台首页</a>
        <a href="<?= e($opBase) ?>/index.php?c=docs">开放文档</a>
        <a href="<?= e($opBase) ?>/admin.php">管理控制台</a>
      </div>
      <div class="op-footer-col">
        <div class="op-footer-t">开发者</div>
        <a href="<?= e($opBase) ?>/index.php?c=docs#quickstart">快速接入</a>
        <a href="<?= e($opBase) ?>/index.php?c=docs#sdk-php">SDK 下载</a>
        <a href="<?= e($opBase) ?>/index.php?c=docs#errors">错误码表</a>
        <a href="<?= e($opBase) ?>/index.php?c=docs#faq">常见问题</a>
      </div>
      <?php if ($hasContact): ?>
      <div class="op-footer-col">
        <div class="op-footer-t">联系我们</div>
        <?php if ($email !== ''): ?>
          <a href="mailto:<?= e($email) ?>"><?= e($email) ?></a>
        <?php endif; ?>
        <?php if ($phone !== ''): ?>
          <span class="op-footer-plain"><?= e($phone) ?></span>
        <?php endif; ?>
        <?php if ($contactUrl !== ''): ?>
          <a href="<?= e($contactUrl) ?>" target="_blank" rel="noopener">在线客服</a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <div class="op-footer-col">
        <div class="op-footer-t">账户</div>
        <a href="<?= e($opBase) ?>/index.php?c=user&amp;a=login">登录</a>
        <a href="<?= e($opBase) ?>/index.php?c=user&amp;a=register">注册</a>
        <a href="<?= e($opBase) ?>/index.php?c=user&amp;a=findpwd">找回密码</a>
      </div>
    </div>
  </div>

  <div class="op-footer-copy">
    <?php if ($footerText !== ''): ?>
      <div class="op-footer-line"><?= e($footerText) ?></div>
    <?php endif; ?>

    <div class="op-footer-line op-footer-legal">
      <span><?= e($copyright) ?></span>
      <span class="op-footer-dot">·</span>
      <span><?= e(site_name()) ?> v<?= e(UIC_VERSION) ?></span>
      <?php if ($icp !== ''): ?>
        <span class="op-footer-dot">·</span>
        <?php if ($icpLink !== ''): ?>
          <a href="<?= e($icpLink) ?>" target="_blank" rel="noopener"><?= e($icp) ?></a>
        <?php else: ?>
          <span><?= e($icp) ?></span>
        <?php endif; ?>
      <?php endif; ?>
      <?php if ($police !== ''): ?>
        <span class="op-footer-dot">·</span>
        <?php if ($policeLink !== ''): ?>
          <a href="<?= e($policeLink) ?>" target="_blank" rel="noopener"><?= e($police) ?></a>
        <?php else: ?>
          <span><?= e($police) ?></span>
        <?php endif; ?>
      <?php endif; ?>
      <span class="op-footer-dot">·</span>
      <a href="<?= e($opBase) ?>/index.php?c=page&amp;a=terms">服务条款</a>
      <span class="op-footer-dot">·</span>
      <a href="<?= e($opBase) ?>/index.php?c=page&amp;a=privacy">隐私政策</a>
      <span class="op-footer-dot">·</span>
      <a href="<?= e($opBase) ?>/index.php?c=page&amp;a=help">帮助和反馈</a>
    </div>
  </div>

  <?php if ($footerHtml !== ''): ?>
    <div class="op-footer-extra"><?= $footerHtml ?></div>
  <?php endif; ?>
</div>

<div class="toast" id="toast"></div>
<script>
window.UIC_BASE = window.UIC_BASE || '<?= e($opBase) ?>';
/* docs.php 这类独立页面没有 App 对象，这里补最小实现，保证导航「退出」可用 */
if (typeof window.App === 'undefined') {
  window.App = (function () {
    var API = window.UIC_BASE + '/api/index.php';
    function toast(msg, type) {
      var t = document.getElementById('toast');
      if (!t) return;
      t.textContent = msg;
      t.className = 'toast on ' + (type || '');
      clearTimeout(t._timer);
      t._timer = setTimeout(function () { t.className = 'toast ' + (type || ''); }, 2600);
    }
    function req(m, a, data, opt) {
      opt = opt || {};
      var form = new FormData();
      form.append('m', m);
      form.append('a', a);
      var d = data || {};
      for (var k in d) { if (d[k] !== null && d[k] !== undefined) form.append(k, d[k]); }
      return fetch(API + '?m=' + encodeURIComponent(m) + '&a=' + encodeURIComponent(a), {
        method: 'POST', body: form, credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      }).then(function (r) { return r.json(); }).then(function (res) {
        if (res.code !== 0) { toast(res.message || '操作失败', 'err'); }
        else if (opt.okMsg !== false && res.message && res.message !== 'ok') { toast(res.message, 'ok'); }
        return res;
      }).catch(function (e) { toast('网络异常：' + e.message, 'err'); return { code: -1 }; });
    }
    function logout() {
      req('user', 'logout', {}, { okMsg: false }).then(function () {
        location.href = window.UIC_BASE + '/index.php';
      });
    }
    return {
      API: API, req: req, toast: toast, logout: logout,
      val: function (s) { var el = document.querySelector(s); return el ? el.value.trim() : ''; }
    };
  })();
}
</script>
