<?php extract($v);
$list         = $v['list'];
$autoRegister = intval($v['autoRegister']);
$enabledCount = 0;
foreach ($list as $k => $it) {
    if (!empty($it['cfg']['enabled'])) {
        $enabledCount++;
    }
}
?>
<div class="alert alert-info" style="margin-bottom:14px;font-size:12.5px">
  本页为第三方登录平台配置向导。相关键值也可在
  <a href="<?= e($base) ?>/admin.php?c=settings&amp;group=oauth">设置中心 → 第三方登录</a> 统一管理。
</div>
<div class="cs-section">
  <div class="cs-section-h">
    <div>
      <div class="cs-section-t">全局策略</div>
      <div class="cs-section-s">当前已启用 <b><?= intval($enabledCount) ?></b> / <?= count($list) ?> 个平台</div>
    </div>
  </div>
  <div class="card">
    <div class="card-b">
      <label class="check">
        <input type="checkbox" id="autoReg" <?= $autoRegister ? 'checked' : '' ?>>
        未绑定的第三方账号首次登录时，自动创建并绑定本站账号
      </label>
      <div class="hint">关闭后，用户必须先用账号密码登录，再到「账号安全 → 第三方绑定」中绑定第三方账号。</div>
      <div style="margin-top:12px">
        <button class="btn btn-p" id="btnSaveGlobal" type="button">保存策略</button>
      </div>
    </div>
  </div>
</div>

<div class="cs-section">
  <div class="cs-section-h">
    <div>
      <div class="cs-section-t">平台配置</div>
      <div class="cs-section-s">回调地址已按当前站点地址自动生成，请原样填写到对应开放平台</div>
    </div>
  </div>

  <?php foreach ($list as $k => $it):
      $p = $it['meta'];
      $c = $it['cfg'];
      $d = $it['doc'];
      $on = !empty($c['enabled']);
  ?>
  <div class="card" style="margin-bottom:12px">
    <div class="card-h between">
      <span>
        <span class="oauth-dot" style="background:<?= e($p['color']) ?>;display:inline-grid;vertical-align:-5px"><?= e(mb_substr($p['name'], 0, 1)) ?></span>
        <?= e($p['name']) ?>
        <span class="badge badge-<?= $on ? 'ok' : 'mute' ?>" style="margin-left:6px"><?= $on ? '已启用' : '未启用' ?></span>
      </span>
      <label class="check"><input type="checkbox" class="oa-en" data-p="<?= e($k) ?>" <?= $on ? 'checked' : '' ?>> 启用</label>
    </div>
    <div class="card-b">
      <div class="grid g2">
        <div class="field">
          <label class="label"><?= e($d['fields']['appid'] ?? 'AppID / Client ID') ?></label>
          <input class="input oa-appid" data-p="<?= e($k) ?>" value="<?= e($c['appid'] ?? '') ?>" placeholder="在<?= e($p['name']) ?>开放平台申请">
        </div>
        <div class="field">
          <label class="label"><?= e($d['fields']['secret'] ?? 'AppSecret / Client Secret') ?></label>
          <input class="input oa-secret" data-p="<?= e($k) ?>" value="<?= e($c['secret'] ?? '') ?>" placeholder="对应密钥" autocomplete="new-password">
        </div>
      </div>

      <?php if (($p['flow'] ?? '') === 'alipay'): ?>
      <div class="field">
        <label class="label">应用私钥（RSA2）</label>
        <textarea class="input oa-privkey" data-p="<?= e($k) ?>" rows="3" placeholder="支付宝开放平台生成的应用私钥（PKCS#1 / PKCS#8 均可）"><?= e($c['private_key'] ?? '') ?></textarea>
        <div class="hint">支付宝使用网关签名鉴权，必须填写应用私钥；Secret 可留空。</div>
      </div>
      <?php endif; ?>

      <div class="field">
        <label class="label">回调地址（填写到<?= e($p['name']) ?>开放平台）</label>
        <div class="row" style="flex-wrap:nowrap">
          <input class="input mono" id="cb-<?= e($k) ?>" value="<?= e($d['callback']) ?>" readonly>
          <button class="btn btn-s nowrap" type="button" data-copy="cb-<?= e($k) ?>">复制</button>
        </div>
      </div>

      <details>
        <summary style="cursor:pointer;font-size:13px;color:var(--primary);font-weight:600">接入配置说明</summary>
        <div class="muted" style="font-size:12.5px;margin-top:8px">
          <div>申请入口：<a href="<?= e($d['apply']) ?>" target="_blank" rel="noopener"><?= e($d['apply']) ?></a></div>
          <ol style="margin:8px 0 0 18px">
            <?php foreach ($d['steps'] as $s): ?><li style="margin-bottom:5px"><?= $s ?></li><?php endforeach; ?>
          </ol>
          <div style="margin-top:8px">
            参数对应：
            <?php foreach ($d['fields'] as $fk => $fv): ?>
              <span class="badge badge-info" style="margin-right:6px"><?= e($fv) ?> → <?= e($fk) ?></span>
            <?php endforeach; ?>
          </div>
        </div>
      </details>

      <div style="margin-top:12px">
        <button class="btn btn-p oa-save" type="button" data-p="<?= e($k) ?>">保存 <?= e($p['name']) ?> 配置</button>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<script>
(function(){
  function collect(k){
    var en     = document.querySelector('.oa-en[data-p="'+k+'"]');
    var appid  = document.querySelector('.oa-appid[data-p="'+k+'"]');
    var secret = document.querySelector('.oa-secret[data-p="'+k+'"]');
    var pk     = document.querySelector('.oa-privkey[data-p="'+k+'"]');
    return {
      provider: k,
      enabled: (en && en.checked) ? 1 : 0,
      appid: appid ? appid.value.trim() : '',
      secret: secret ? secret.value.trim() : '',
      private_key: pk ? pk.value.trim() : ''
    };
  }
  var btns = document.querySelectorAll('.oa-save');
  for (var i = 0; i < btns.length; i++) {
    btns[i].addEventListener('click', function(){
      var d = collect(this.getAttribute('data-p'));
      Admin.req('admin','oauth_save', d, {okMsg:false}).then(function(res){
        if (res && res.code === 0) { Admin.toast('已保存','ok'); setTimeout(function(){ location.reload(); }, 600); }
      });
    });
  }
  var g = document.getElementById('btnSaveGlobal');
  if (g) {
    g.addEventListener('click', function(){
      var v = document.getElementById('autoReg').checked ? 1 : 0;
      Admin.req('admin','oauth_save_global', { auto_register: v }, {okMsg:false}).then(function(res){
        if (res && res.code === 0) Admin.toast('已保存','ok');
      });
    });
  }
  var cops = document.querySelectorAll('[data-copy]');
  for (var j = 0; j < cops.length; j++) {
    cops[j].addEventListener('click', function(){
      var el = document.getElementById(this.getAttribute('data-copy'));
      if (!el) return;
      el.select(); el.setSelectionRange(0, 99999);
      try { document.execCommand('copy'); Admin.toast('已复制回调地址','ok'); }
      catch (e) { Admin.toast('请手动复制','err'); }
    });
  }
})();
</script>
