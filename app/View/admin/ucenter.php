<?php extract($v);
$cfg     = $v['cfg'];
$ping    = $v['ping'];
$health  = $v['health'];
$calls   = $v['calls'];
$ncfg    = isset($v['noticeCfg']) ? $v['noticeCfg'] : [];
$nact    = isset($v['noticeActions']) ? $v['noticeActions'] : [];
$nstat   = isset($v['noticeStats']) ? $v['noticeStats'] : ['total' => 0, 'fail' => 0, 'last' => null];
$notices = isset($v['notices']) ? $v['notices'] : [];
$nOn     = !empty($ncfg['enabled']);
?>
<div class="alert alert-info" style="margin-bottom:14px;font-size:12.5px">
  本页提供 UCenter 连接测试与通知自检工具。全部通信 / 通知参数也可在
  <a href="<?= e($base) ?>/admin.php?c=settings&amp;group=ucenter">设置中心 → UCenter 对接</a> 统一管理。
</div>
<div class="card" style="margin-bottom:14px">
  <div class="card-h between">
    <span>UCenter 连接配置</span>
    <a class="btn btn-s btn-p" href="<?= e($base) ?>/admin.php?c=ucenter&check=1">一键体检</a>
  </div>
  <div class="card-b">
    <form id="ucForm">
      <div class="grid g2">
        <div class="field" style="margin-bottom:10px">
          <label class="label" for="uc_driver">驱动模式</label>
          <select class="input" id="uc_driver">
            <option value="http" <?= ($cfg['driver'] ?? '') === 'http' ? 'selected' : '' ?>>http — 连接真实 UCenter 服务端</option>
            <option value="mock" <?= ($cfg['driver'] ?? '') === 'mock' ? 'selected' : '' ?>>mock — 本地模拟（开发调试用）</option>
          </select>
          <div class="hint">Mock 模式会把账号写入主库 <?= htmlspecialchars(Database::tableName('uc_members')) ?> 表，用于没有 UCenter 时跑通流程。</div>
        </div>
        <div class="field" style="margin-bottom:10px">
          <label class="label" for="uc_enabled">启用状态</label>
          <select class="input" id="uc_enabled">
            <option value="1" <?= Config::get('uc_enabled', 1) ? 'selected' : '' ?>>启用</option>
            <option value="0" <?= !Config::get('uc_enabled', 1) ? 'selected' : '' ?>>停用</option>
          </select>
        </div>
        <div class="field" style="margin-bottom:10px">
          <label class="label" for="uc_url">UCenter 地址（API 地址）</label>
          <input class="input" id="uc_url" value="<?= e(Config::get('uc_url', '')) ?>" placeholder="http://127.0.0.2/uc_server">
        </div>
        <div class="field" style="margin-bottom:10px">
          <label class="label" for="uc_ip">UCenter 服务器 IP <span class="faint" style="font-weight:400">（可留空）</span></label>
          <input class="input" id="uc_ip" value="<?= e(Config::get('uc_ip', '')) ?>" placeholder="域名解析异常时直连">
        </div>
        <div class="field" style="margin-bottom:10px">
          <label class="label" for="uc_key">通信密钥 <span class="faint" style="font-weight:400">（需与 UCenter 应用配置完全一致）</span></label>
          <input class="input mono" id="uc_key" placeholder="留空表示不修改">
        </div>
        <div class="field" style="margin-bottom:10px">
          <label class="label" for="uc_appid">本应用在 UCenter 的 AppID</label>
          <input class="input mono" id="uc_appid" value="<?= e((string)Config::get('uc_appid', 1)) ?>">
        </div>
        <div class="field" style="margin-bottom:10px">
          <label class="label" for="uc_timeout">请求超时（秒）</label>
          <input class="input" id="uc_timeout" value="<?= e((string)Config::get('uc_timeout', 10)) ?>">
        </div>
        <div class="field" style="margin-bottom:10px">
          <label class="label" for="uc_ssl_verify">HTTPS 证书校验</label>
          <select class="input" id="uc_ssl_verify">
            <option value="1" <?= Config::get('uc_ssl_verify', true) ? 'selected' : '' ?>>校验（推荐）</option>
            <option value="0" <?= !Config::get('uc_ssl_verify', true) ? 'selected' : '' ?>>跳过校验（仅临时排障用）</option>
          </select>
          <div class="hint">Windows 环境 php.ini 常未配置 curl.cainfo，会报 “unable to get local issuer certificate”，表现为通信失败。</div>
        </div>
        <div class="field" style="margin-bottom:10px">
          <label class="label" for="uc_ca_file">CA 根证书包路径 <span class="faint" style="font-weight:400">（可留空）</span></label>
          <input class="input mono" id="uc_ca_file" value="<?= e((string)Config::get('uc_ca_file', '')) ?>" placeholder="留空则自动使用 data/cacert.pem">
          <div class="hint">留空时自动使用项目自带 <span class="mono">data/cacert.pem</span>（<?= is_file(BASE_PATH . '/data/cacert.pem') ? '已就位' : '<b style="color:#e03131">缺失，HTTPS 通信会失败</b>' ?>）。</div>
        </div>
      </div>
      <div class="row">
        <button class="btn btn-p" type="submit">保存配置</button>
        <button class="btn" type="button" onclick="UCTest.ping()">连接测试</button>
      </div>
    </form>
  </div>
</div>

<?php if ($health): ?>
<div class="card" style="margin-bottom:14px">
  <div class="card-h">UCenter 接口体检结果</div>
  <div class="card-b">
    <div class="grid g3">
      <?php foreach ($health as $k => $item): ?>
        <div class="stat">
          <div class="stat-v" style="font-size:15px">
            <span class="badge badge-<?= $item['ok'] ? 'ok' : 'err' ?>"><?= $item['ok'] ? '正常' : '异常' ?></span>
          </div>
          <div class="stat-l"><?= e($item['label']) ?></div>
          <div class="faint" style="font-size:12px;margin-top:3px"><?= e($item['msg']) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="card" style="margin-bottom:14px">
  <div class="card-h">单接口测试</div>
  <div class="card-b">
    <div class="field">
      <label class="label" for="method">测试项</label>
      <select class="input" id="method">
        <option value="checkname">用户名接口（user.checkname）</option>
        <option value="checkemail">邮箱接口（user.checkemail）</option>
        <option value="register">注册接口（user.register）</option>
        <option value="login">登录接口（user.login）</option>
        <option value="getuser">用户查询（user.get_user）</option>
        <option value="editpwd">修改密码（user.edit）</option>
        <option value="editprofile">修改资料（user.edit 邮箱）</option>
      </select>
    </div>
    <div class="grid g3">
      <div class="field" style="margin-bottom:10px"><label class="label" for="username">用户名</label><input class="input" id="username" placeholder="testuser"></div>
      <div class="field" style="margin-bottom:10px"><label class="label" for="email">邮箱</label><input class="input" id="email" placeholder="a@b.com"></div>
      <div class="field" style="margin-bottom:10px"><label class="label" for="account">账号（登录/查询）</label><input class="input" id="account" placeholder="用户名或邮箱"></div>
      <div class="field" style="margin-bottom:10px"><label class="label" for="password">密码</label><input class="input" id="password" type="password"></div>
      <div class="field" style="margin-bottom:10px"><label class="label" for="new_password">新密码</label><input class="input" id="new_password" type="password"></div>
      <div class="field" style="margin-bottom:10px"><label class="label" for="old_password">旧密码</label><input class="input" id="old_password" type="password"></div>
    </div>
    <div class="row">
      <button class="btn btn-p" onclick="UCTest.call()">执行测试</button>
      <button class="btn" onclick="document.getElementById('out').textContent=''">清空输出</button>
    </div>
    <div class="divider"></div>
    <pre class="code" id="out">执行结果会显示在这里</pre>
  </div>
</div>

<!-- ==================== UCenter → 应用 通知接收 ==================== -->
<div class="card" style="margin-bottom:14px">
  <div class="card-h between">
    <span>通知接收（UCenter → 本应用）</span>
    <span>
      <span class="badge badge-<?= $nOn ? 'ok' : 'err' ?>"><?= $nOn ? '已开启' : '已关闭' ?></span>
    </span>
  </div>
  <div class="card-b">
    <div class="hint" style="margin-bottom:12px">
      UCenter 后台「基本设置 → 通知设置」中的每个开关开启后，UCenter 都会向下面的回调地址推送通知。
      本应用已内置接收端并覆盖全部通知动作，收到后一律回执成功，不会再出现「通知失败 / 无法过来数据」。
    </div>

    <div class="grid g2" style="margin-bottom:6px">
      <div class="field" style="margin-bottom:10px">
        <label class="label">本应用的通知回调地址（填入 UCenter 应用的「应用 URL」）</label>
        <div class="row" style="gap:8px">
          <input class="input mono" id="ucCallback" value="<?= e($ncfg['callback'] ?? '') ?>" readonly>
          <button class="btn" type="button" onclick="UCNotify.copy()">复制</button>
        </div>
        <div class="hint">UCenter「应用管理」中该应用的接口文件名称请填 <b class="mono">api/uc.php</b>（留空即默认值）。</div>
      </div>
      <div class="field" style="margin-bottom:10px">
        <label class="label">接收状态</label>
        <div class="grid g3" style="gap:8px">
          <div class="stat"><div class="stat-v" style="font-size:18px"><?= intval($nstat['total']) ?></div><div class="stat-l">累计收到</div></div>
          <div class="stat"><div class="stat-v" style="font-size:18px<?= intval($nstat['fail']) ? ';color:#e05252' : '' ?>"><?= intval($nstat['fail']) ?></div><div class="stat-l">异常/拒绝</div></div>
          <div class="stat"><div class="stat-v" style="font-size:13px">
            <?= $nstat['last'] ? date('m-d H:i', intval($nstat['last']['created_at'])) : '—' ?>
          </div><div class="stat-l">最近一条</div></div>
        </div>
      </div>
    </div>

    <form id="ucNotifyForm">
      <div class="grid g4" style="gap:10px">
        <div class="field" style="margin-bottom:10px">
          <label class="label" for="n_enabled">接收总开关</label>
          <select class="input" id="n_enabled">
            <option value="1" <?= $nOn ? 'selected' : '' ?>>开启</option>
            <option value="0" <?= !$nOn ? 'selected' : '' ?>>关闭</option>
          </select>
        </div>
        <div class="field" style="margin-bottom:10px">
          <label class="label" for="n_ttl">时间戳允许偏差（秒）</label>
          <input class="input" id="n_ttl" value="<?= e((string)($ncfg['ttl'] ?? 3600)) ?>">
          <div class="hint">0 = 不校验；服务器时间不同步时可关闭。</div>
        </div>
        <div class="field" style="margin-bottom:10px">
          <label class="label" for="n_strict">未知通知动作</label>
          <select class="input" id="n_strict">
            <option value="0" <?= empty($ncfg['strict']) ? 'selected' : '' ?>>宽容（回执成功）</option>
            <option value="1" <?= !empty($ncfg['strict']) ? 'selected' : '' ?>>严格（直接拒绝）</option>
          </select>
          <div class="hint">UCenter 新增动作时建议保持宽容。</div>
        </div>
        <div class="field" style="margin-bottom:10px">
          <label class="label" for="n_keep">记录保留条数</label>
          <input class="input" id="n_keep" value="<?= e((string)($ncfg['keep'] ?? 500)) ?>">
        </div>
        <div class="field" style="margin-bottom:10px">
          <label class="label" for="n_plain">明文直传</label>
          <select class="input" id="n_plain">
            <option value="0" <?= empty($ncfg['plain']) ? 'selected' : '' ?>>关闭（推荐）</option>
            <option value="1" <?= !empty($ncfg['plain']) ? 'selected' : '' ?>>开启（仅调试）</option>
          </select>
          <div class="hint">明文不经过签名校验，开启后可被伪造，仅放行 test 类无害动作。</div>
        </div>
      </div>
      <div class="field" style="margin-bottom:12px">
        <label class="label" for="n_ips">来源 IP 白名单 <span class="faint" style="font-weight:400">（逗号分隔，留空不限制）</span></label>
        <input class="input mono" id="n_ips" value="<?= e((string)($ncfg['ips'] ?? '')) ?>" placeholder="例如 1.2.3.4, 5.6.7.8">
      </div>

      <div class="divider"></div>
      <div class="between" style="margin-bottom:8px">
        <b style="font-size:13.5px">通知动作开关</b>
        <span class="faint" style="font-size:12.5px">与 UCenter 后台「通知设置」逐项对应；关闭后仍回执成功，只是不在本应用落地业务</span>
      </div>
      <div class="t-scroll" style="max-height:none">
        <table class="table compact">
          <thead><tr><th style="width:56px">启用</th><th style="width:200px">通知动作</th><th>UCenter 后台对应开关</th><th style="width:70px">返回</th></tr></thead>
          <tbody>
          <?php foreach ($nact as $act => $info): ?>
            <tr>
              <td><input type="checkbox" class="n-act" data-act="<?= e($act) ?>" <?= $info['enabled'] ? 'checked' : '' ?>></td>
              <td class="mono"><?= e($act) ?></td>
              <td><?= e($info['name']) ?><div class="faint" style="font-size:12px"><?= e($info['switch']) ?></div></td>
              <td><?= $info['return'] ? '<span class="badge badge-ok">数据</span>' : '<span class="badge">1</span>' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="row" style="margin-top:12px">
        <button class="btn btn-p" type="submit">保存通知配置</button>
        <button class="btn" type="button" onclick="UCNotify.selftest('test')">自测接收（test）</button>
        <button class="btn" type="button" onclick="UCNotify.selftest('updateapps')">自测 updateapps</button>
        <button class="btn" type="button" onclick="UCNotify.clear()">清空记录</button>
      </div>
      <div class="hint" style="margin-top:8px">
        自测会用与 UCenter 完全一致的方式（authcode 加密 <b class="mono">code</b> 参数）向本站回调地址发一条真实通知，验证「网络可达 + 密钥一致 + 解密正确 + 回执成功」。
      </div>
      <div class="divider"></div>
      <pre class="code" id="nOut">自测结果会显示在这里</pre>
    </form>
  </div>
</div>

<div class="card" style="margin-bottom:14px">
  <div class="card-h between">
    <span>通知接收记录 <span class="faint" style="font-weight:400;font-size:12.5px">最近 <?= count($notices) ?> 条</span></span>
    <span class="faint" style="font-size:12.5px">UCenter 推过来的每条通知都记录在此</span>
  </div>
  <div class="card-b tight t-scroll">
    <?php if (empty($notices)): ?>
      <div class="empty">暂无通知记录。可先点上方「自测接收」验证链路，再到 UCenter 后台开启各通知开关。</div>
    <?php else: ?>
    <table class="table compact">
      <thead><tr><th>时间</th><th>通知动作</th><th>状态</th><th>耗时</th><th>返回</th><th>说明</th><th>来源 IP</th></tr></thead>
      <tbody>
      <?php foreach ($notices as $n):
          $st = $n['status'];
          $cls = $st === 'ok' ? 'ok' : ($st === 'ignored' ? '' : 'err');
      ?>
        <tr>
          <td class="nowrap faint"><?= date('m-d H:i:s', intval($n['created_at'])) ?></td>
          <td class="mono"><?= e($n['action']) ?></td>
          <td><span class="badge <?= $cls ? 'badge-' . $cls : '' ?>"><?= e($st) ?></span></td>
          <td class="mono"><?= intval($n['cost_ms']) ?>ms</td>
          <td class="mono" style="max-width:110px;overflow:hidden;text-overflow:ellipsis"><?= e($n['result']) ?></td>
          <td style="max-width:300px"><?= e($n['message']) ?></td>
          <td class="mono faint"><?= e($n['ip']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card-h between">
    <span>UCenter 通信记录 <span class="faint" style="font-weight:400;font-size:12.5px">最近 <?= count($calls) ?> 条</span></span>
    <span class="faint" style="font-size:12.5px">排错时可直接查看原始响应</span>
  </div>
  <div class="card-b tight t-scroll">
    <?php if (empty($calls)): ?><div class="empty">暂无通信记录</div><?php else: ?>
    <table class="table compact">
      <thead><tr><th>时间</th><th>方法</th><th>状态</th><th>耗时</th><th>请求参数</th><th>响应/说明</th></tr></thead>
      <tbody>
      <?php foreach ($calls as $cc): ?>
        <tr>
          <td class="nowrap faint"><?= date('H:i:s', intval($cc['created_at'])) ?></td>
          <td class="mono"><?= e($cc['method']) ?></td>
          <td><?= $cc['status'] === 'ok' ? '<span class="badge badge-ok">ok</span>' : '<span class="badge badge-err">fail</span>' ?></td>
          <td class="mono"><?= intval($cc['cost_ms']) ?>ms</td>
          <td class="mono faint" style="max-width:220px;overflow:hidden;text-overflow:ellipsis"><?= e($cc['request']) ?></td>
          <td class="mono" style="max-width:260px;overflow:hidden;text-overflow:ellipsis"><?= e(mb_substr($cc['response'], 0, 200)) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<script>
var UCTest = {
  ping: function(){
    Admin.req('admin','uc_call',{method:'ping'},{okMsg:false}).then(function(res){
      Admin.toast(res.code === 0 ? '连接正常' : res.message, res.code === 0 ? 'ok' : 'err');
      document.getElementById('out').textContent = JSON.stringify(res, null, 2);
    });
  },
  call: function(){
    Admin.req('admin','uc_call',{
      method: document.getElementById('method').value,
      username: document.getElementById('username').value.trim(),
      email: document.getElementById('email').value.trim(),
      account: document.getElementById('account').value.trim(),
      password: document.getElementById('password').value.trim(),
      new_password: document.getElementById('new_password').value.trim(),
      old_password: document.getElementById('old_password').value.trim()
    }, {okMsg:false}).then(function(res){
      document.getElementById('out').textContent = JSON.stringify(res, null, 2);
      if (res.code !== 0) Admin.toast(res.message, 'err');
    });
  }
};
// ---------------- UCenter → 应用 通知接收 ----------------
var UCNotify = {
  copy: function(){
    var el = document.getElementById('ucCallback');
    if (!el) return;
    var ok = false;
    try { el.select(); el.setSelectionRange(0, 99999); ok = document.execCommand('copy'); } catch(e){}
    if (!ok && navigator.clipboard) { navigator.clipboard.writeText(el.value); ok = true; }
    Admin.toast(ok ? '已复制回调地址' : '请手动选中复制', ok ? 'ok' : 'err');
  },
  selftest: function(action){
    document.getElementById('nOut').textContent = '正在自测 ' + action + ' …';
    Admin.req('admin','uc_notify_test',{action:action},{okMsg:false}).then(function(res){
      document.getElementById('nOut').textContent = JSON.stringify(res, null, 2);
      if (res.code !== 0) { Admin.toast(res.message || '自测失败', 'err'); return; }
      var d = res.data || {};
      Admin.toast(d.pass ? ('自测通过：' + action + ' 回执正常') : ('自测未通过：' + action), d.pass ? 'ok' : 'err');
    });
  },
  clear: function(){
    if (!confirm('确定清空全部通知接收记录？')) return;
    Admin.req('admin','uc_notify_clear',{},{okMsg:true}).then(function(){
      setTimeout(function(){ location.reload(); }, 700);
    });
  }
};
document.getElementById('ucNotifyForm').addEventListener('submit', function(ev){
  ev.preventDefault();
  var acts = {};
  var boxes = document.querySelectorAll('.n-act');
  for (var i = 0; i < boxes.length; i++) {
    acts[boxes[i].getAttribute('data-act')] = boxes[i].checked ? 1 : 0;
  }
  Admin.req('admin','uc_notify_save',{
    uc_notify_enabled: document.getElementById('n_enabled').value,
    uc_notify_ttl: document.getElementById('n_ttl').value.trim(),
    uc_notify_strict: document.getElementById('n_strict').value,
    uc_notify_keep: document.getElementById('n_keep').value.trim(),
    uc_notify_ips: document.getElementById('n_ips').value.trim(),
    uc_notify_plain: document.getElementById('n_plain').value,
    uc_notify_actions: JSON.stringify(acts)
  }, {after:function(){ setTimeout(function(){ location.reload(); }, 600); }});
});

document.getElementById('ucForm').addEventListener('submit', function(ev){
  ev.preventDefault();
  Admin.req('admin','uc_save',{
    uc_driver: document.getElementById('uc_driver').value,
    uc_enabled: document.getElementById('uc_enabled').value,
    uc_url: document.getElementById('uc_url').value.trim(),
    uc_ip: document.getElementById('uc_ip').value.trim(),
    uc_key: document.getElementById('uc_key').value.trim(),
    uc_appid: document.getElementById('uc_appid').value.trim(),
    uc_timeout: document.getElementById('uc_timeout').value.trim(),
    uc_ssl_verify: document.getElementById('uc_ssl_verify').value,
    uc_ca_file: document.getElementById('uc_ca_file').value.trim()
  }, {after:function(){ setTimeout(function(){ location.reload(); }, 600); }});
});
</script>
