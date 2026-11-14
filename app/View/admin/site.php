<?php extract($v);
/** 站点信息设置：基础信息 / 备案信息 / 联系方式 / 底部信息 */
$g = function ($k, $d = '') {
    return (string)Config::get($k, $d);
};
?>
<div class="alert alert-info" style="margin-bottom:14px;font-size:12.5px">
  本页为站点信息专用表单（含页脚预览）。全部站点类设置项也可在
  <a href="<?= e($base) ?>/admin.php?c=settings&amp;group=site">设置中心 → 站点信息</a> 统一管理。
</div>
<div class="cs-section">
  <div class="cs-section-h">
    <div>
      <div class="cs-section-title">站点信息</div>
      <div class="cs-section-sub">设置站点名称、备案号、联系方式与页脚展示内容</div>
    </div>
    <div class="card-tools">
      <a class="btn btn-s" href="<?= e($base) ?>/index.php" target="_blank">预览前台</a>
    </div>
  </div>
</div>

<form id="siteForm" autocomplete="off">

  <!-- 基础信息 -->
  <div class="cs-section">
    <div class="op-section-head">
      <h3 class="op-section-title" style="font-size:16px">基础信息</h3>
      <p class="op-section-sub">站点名称会显示在浏览器标签、导航与页脚</p>
    </div>
    <div class="card">
      <div class="card-b">
        <div class="grid g2">
          <div class="field">
            <label class="label" for="site_name">站点名称</label>
            <input class="input" id="site_name" value="<?= e($g('site_name', '统一身份认证中心')) ?>" placeholder="统一身份认证中心">
          </div>
          <div class="field">
            <label class="label" for="site_slogan">站点标语</label>
            <input class="input" id="site_slogan" value="<?= e($g('site_slogan')) ?>" placeholder="一次登录，处处通行">
          </div>
          <div class="field">
            <label class="label" for="site_url">站点根地址</label>
            <input class="input" id="site_url" value="<?= e($g('site_url')) ?>" placeholder="留空自动推断，如 https://auth.example.com">
            <div class="hint">用于生成回调地址等绝对链接，建议显式填写。</div>
          </div>
          <div class="field">
            <label class="label" for="site_logo">站点 Logo 地址</label>
            <input class="input" id="site_logo" value="<?= e($g('site_logo')) ?>" placeholder="留空使用默认标识">
          </div>
          <div class="field">
            <label class="label" for="site_company">主体 / 公司名称</label>
            <input class="input" id="site_company" value="<?= e($g('site_company')) ?>" placeholder="某某科技有限公司">
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- 备案信息 -->
  <div class="cs-section">
    <div class="op-section-head">
      <h3 class="op-section-title" style="font-size:16px">备案信息</h3>
      <p class="op-section-sub">填写后将展示在开放平台页脚，ICP 备案号默认跳转工信部备案查询</p>
    </div>
    <div class="card">
      <div class="card-b">
        <div class="grid g2">
          <div class="field">
            <label class="label" for="site_icp">ICP 备案号</label>
            <input class="input" id="site_icp" value="<?= e($g('site_icp')) ?>" placeholder="京ICP备12345678号">
            <div class="hint">格式示例：京ICP备12345678号-1；留空则不显示。</div>
          </div>
          <div class="field">
            <label class="label" for="site_icp_link">备案查询地址</label>
            <input class="input mono" id="site_icp_link" value="<?= e($g('site_icp_link', 'https://beian.miit.gov.cn/')) ?>" placeholder="https://beian.miit.gov.cn/">
          </div>
          <div class="field">
            <label class="label" for="site_police">公安联网备案号</label>
            <input class="input" id="site_police" value="<?= e($g('site_police')) ?>" placeholder="京公网安备11010802012345号">
          </div>
          <div class="field">
            <label class="label" for="site_police_link">公安备案查询地址</label>
            <input class="input mono" id="site_police_link" value="<?= e($g('site_police_link')) ?>" placeholder="http://www.beian.gov.cn/...">
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- 联系方式 -->
  <div class="cs-section">
    <div class="op-section-head">
      <h3 class="op-section-title" style="font-size:16px">联系方式</h3>
      <p class="op-section-sub">展示在页脚，便于开发者与你取得联系</p>
    </div>
    <div class="card">
      <div class="card-b">
        <div class="grid g2">
          <div class="field">
            <label class="label" for="site_contact_email">联系邮箱</label>
            <input class="input" id="site_contact_email" value="<?= e($g('site_contact_email')) ?>" placeholder="dev@example.com">
          </div>
          <div class="field">
            <label class="label" for="site_contact_phone">联系电话</label>
            <input class="input" id="site_contact_phone" value="<?= e($g('site_contact_phone')) ?>" placeholder="400-000-0000">
          </div>
          <div class="field">
            <label class="label" for="site_contact_url">在线客服 / 工单地址</label>
            <input class="input mono" id="site_contact_url" value="<?= e($g('site_contact_url')) ?>" placeholder="https://support.example.com">
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- 底部信息 -->
  <div class="cs-section">
    <div class="op-section-head">
      <h3 class="op-section-title" style="font-size:16px">底部信息</h3>
      <p class="op-section-sub">版权声明与页脚附加内容</p>
    </div>
    <div class="card">
      <div class="card-b">
        <div class="field">
          <label class="label" for="site_copyright">版权声明</label>
          <input class="input" id="site_copyright" value="<?= e($g('site_copyright')) ?>" placeholder="留空自动生成，如：© 2026 某某科技有限公司">
        </div>
        <div class="field">
          <label class="label" for="site_footer_text">底部自定义文案</label>
          <input class="input" id="site_footer_text" value="<?= e($g('site_footer_text')) ?>" placeholder="如：本站为开发者提供统一身份认证服务">
        </div>
        <div class="field">
          <label class="label" for="site_footer_html">底部附加 HTML <span class="faint" style="font-weight:400">（可选）</span></label>
          <textarea class="input" id="site_footer_html" rows="3" placeholder="如统计代码；请仅填写可信来源的代码"><?= e($g('site_footer_html')) ?></textarea>
          <div class="hint">该内容会原样输出到前台页脚，请谨慎填写，避免引入外部脚本风险。</div>
        </div>
      </div>
    </div>
  </div>

  <div class="cs-section">
    <div class="row">
      <button class="btn btn-p" type="submit">保存站点信息</button>
      <button class="btn" type="button" onclick="location.reload()">重置</button>
    </div>
  </div>
</form>

<script>
document.getElementById('siteForm').addEventListener('submit', function(ev){
  ev.preventDefault();
  var ids = ['site_name','site_slogan','site_url','site_logo','site_company',
             'site_icp','site_icp_link','site_police','site_police_link',
             'site_contact_email','site_contact_phone','site_contact_url',
             'site_copyright','site_footer_text','site_footer_html'];
  var d = {};
  ids.forEach(function(id){
    var el = document.getElementById(id);
    d[id] = el ? el.value.trim() : '';
  });
  Admin.req('admin','site_save', d, {okMsg:false}).then(function(res){
    if (res && res.code === 0) {
      Admin.toast('站点信息已保存','ok');
      setTimeout(function(){ location.reload(); }, 700);
    }
  });
});
</script>
