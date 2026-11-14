<?php extract($v); $apps = $v['apps']; ?>
<div class="card" style="margin-bottom:14px">
  <div class="card-h between">
    <span id="formTitle">新增应用</span>
    <span class="faint" style="font-weight:400;font-size:12.5px">AppID / AppSecret / 回调地址 / 应用状态</span>
  </div>
  <div class="card-b">
    <form id="appForm">
      <input type="hidden" id="id" value="">
      <div class="grid g2">
        <div class="field" style="margin-bottom:10px">
          <label class="label" for="name">应用名称 *</label>
          <input class="input" id="name" placeholder="例如：A 网站">
        </div>
        <div class="field" style="margin-bottom:10px">
          <label class="label" for="app_id">AppID <span class="faint" style="font-weight:400">（留空自动生成）</span></label>
          <input class="input mono" id="app_id" placeholder="app_xxxxxxx">
        </div>
        <div class="field" style="margin-bottom:10px">
          <label class="label" for="home_url">站点首页</label>
          <input class="input" id="home_url" placeholder="https://a.example.com">
        </div>
        <div class="field" style="margin-bottom:10px">
          <label class="label" for="description">备注</label>
          <input class="input" id="description" placeholder="选填">
        </div>
      </div>
      <div class="field" style="margin-bottom:10px">
        <label class="label" for="callbacks">回调地址白名单 * <span class="faint" style="font-weight:400">（每行一个）</span></label>
        <textarea class="input" id="callbacks" rows="3" placeholder="https://a.example.com/sso/callback.php&#10;https://a.example.com/sso/back.php"></textarea>
        <div class="hint">Ticket 只会跳转到白名单内的地址，防止开放重定向。</div>
      </div>
      <div class="field row" style="gap:16px;margin-bottom:12px">
        <label class="check"><input type="checkbox" id="status" checked> 启用</label>
        <label class="check"><input type="checkbox" id="sso_enabled" checked> 允许单点登录</label>
      </div>
      <div class="row">
        <button class="btn btn-p" type="submit">保存应用</button>
        <button class="btn" type="button" onclick="Apps.resetForm()">清空</button>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-h between">
    <span>应用列表 <span class="faint" style="font-weight:400;font-size:12.5px">共 <?= count($apps) ?> 个</span></span>
  </div>
  <div class="card-b tight t-scroll">
    <?php if (empty($apps)): ?><div class="empty">还没有接入任何应用</div><?php else: ?>
    <table class="table">
      <thead><tr>
        <th>名称</th><th>AppID</th><th>AppSecret</th><th>回调地址</th><th>状态</th><th>SSO</th><th class="right">操作</th>
      </tr></thead>
      <tbody>
      <?php foreach ($apps as $a): ?>
        <tr>
          <td><b><?= e($a['name']) ?></b><div class="faint" style="font-size:12px"><?= e($a['description']) ?></div></td>
          <td class="mono"><?= e($a['app_id']) ?></td>
          <td class="mono faint" title="点击查看完整密钥">••••••••<span style="display:none" id="sec_<?= $a['id'] ?>"><?= e($a['app_secret']) ?></span></td>
          <td style="font-size:12px"><?php foreach ((array)$a['callbacks'] as $cb): ?><div class="mono"><?= e($cb) ?></div><?php endforeach; ?></td>
          <td><?= $a['status'] ? '<span class="badge badge-ok">启用</span>' : '<span class="badge badge-err">禁用</span>' ?></td>
          <td><?= $a['sso_enabled'] ? '<span class="badge badge-info">开启</span>' : '<span class="badge badge-mute">关闭</span>' ?></td>
          <td class="right">
            <button class="btn btn-s" onclick="Apps.test(<?= intval($a['id']) ?>)">通信测试</button>
            <button class="btn btn-s" onclick="Apps.secret(<?= intval($a['id']) ?>)">重置密钥</button>
            <button class="btn btn-s" onclick="Apps.toggle(<?= intval($a['id']) ?>,<?= $a['status'] ? 0 : 1 ?>)"><?= $a['status'] ? '禁用' : '启用' ?></button>
            <?php /* JSON 进 HTML 属性：必须 e() 转义 + HEX 系列标志，
                     否则名称/描述里的单引号会闭合 onclick='...' 造成 XSS */ ?>
            <button class="btn btn-s" onclick='Apps.edit(<?= e(json_encode($a, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP)) ?>)'>编辑</button>
            <button class="btn btn-s btn-d" onclick="Apps.del(<?= intval($a['id']) ?>,'<?= e($a['name']) ?>')">删除</button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<div class="card" style="margin-top:14px">
  <div class="card-h">接入示例代码（PHP）</div>
  <div class="card-b">
    <pre class="code"><span class="c">// 1) 未登录时跳转到认证中心</span>
<span class="k">$authUrl</span> = <span class="s">'<?= e(site_url('index.php')) ?>?c=sso&amp;a=authorize&amp;app_id=你的AppID&amp;redirect='</span> . urlencode(<span class="k">$thisUrl</span>);
header(<span class="s">'Location: ' . $authUrl</span>);

<span class="c">// 2) 回调页：用 ticket 换取 token（服务端 POST，AppSecret 不外泄）</span>
<span class="k">$token</span> = post_api(<span class="s">'<?= e(site_url('api/index.php')) ?>?m=sso&amp;a=exchange'</span>, [
    <span class="s">'ticket'</span> =&gt; <span class="k">$_GET</span>[<span class="s">'ticket'</span>],
    <span class="s">'app_id'</span> =&gt; <span class="s">'你的AppID'</span>,
    <span class="s">'app_secret'</span> =&gt; <span class="s">'你的AppSecret'</span>,
])[<span class="s">'data'</span>][<span class="s">'token'</span>];

<span class="c">// 3) 用 token 拉取用户信息 / 单点退出</span>
<span class="k">$user</span> = post_api(<span class="s">'...m=sso&amp;a=userinfo'</span>, [<span class="s">'token'</span> =&gt; <span class="k">$token</span>])[<span class="s">'data'</span>][<span class="s">'user'</span>];
post_api(<span class="s">'...m=sso&amp;a=logout'</span>, [<span class="s">'token'</span> =&gt; <span class="k">$token</span>]);</pre>
  </div>
</div>

<script>
var Apps = {
  resetForm: function(){
    ['id','name','app_id','home_url','description','callbacks'].forEach(function(k){
      document.getElementById(k).value = '';
    });
    document.getElementById('status').checked = true;
    document.getElementById('sso_enabled').checked = true;
    document.getElementById('formTitle').textContent = '新增应用';
  },
  edit: function(a){
    document.getElementById('id').value = a.id;
    document.getElementById('name').value = a.name;
    document.getElementById('app_id').value = a.app_id;
    document.getElementById('home_url').value = a.home_url || '';
    document.getElementById('description').value = a.description || '';
    document.getElementById('callbacks').value = (a.callbacks || []).join('\n');
    document.getElementById('status').checked = !!a.status;
    document.getElementById('sso_enabled').checked = !!a.sso_enabled;
    document.getElementById('formTitle').textContent = '编辑应用：' + a.name;
    window.scrollTo({top:0, behavior:'smooth'});
  },
  test: function(id){
    Admin.req('admin','app_test',{id:id},{okMsg:false}).then(function(res){
      Admin.toast(res.message, res.code === 0 ? 'ok' : 'err');
    });
  },
  secret: function(id){
    if (!confirm('重置后原有接入将立即失效，确定继续？')) return;
    Admin.req('admin','app_reset_secret',{id:id},{okMsg:false}).then(function(res){
      if (res.code !== 0) return;
      var s = res.data.app_secret;
      prompt('新的 AppSecret 如下，请立即保存并更新到你的站点：', s);
      location.reload();
    });
  },
  toggle: function(id, status){
    Admin.req('admin','app_toggle',{id:id, status:status},{after:function(){ setTimeout(function(){location.reload();},400); }});
  },
  del: function(id, name){
    if (!confirm('确定删除应用「' + name + '」？其 Token/Ticket 也会一并清除。')) return;
    Admin.req('admin','app_delete',{id:id},{after:function(){ setTimeout(function(){location.reload();},400); }});
  }
};
document.getElementById('appForm').addEventListener('submit', function(ev){
  ev.preventDefault();
  var cbs = document.getElementById('callbacks').value.split(/[\r\n,]+/).map(function(s){return s.trim();}).filter(Boolean);
  Admin.req('admin','app_save',{
    id: document.getElementById('id').value,
    name: document.getElementById('name').value.trim(),
    app_id: document.getElementById('app_id').value.trim(),
    home_url: document.getElementById('home_url').value.trim(),
    description: document.getElementById('description').value.trim(),
    callbacks: cbs,
    status: document.getElementById('status').checked ? 1 : 0,
    sso_enabled: document.getElementById('sso_enabled').checked ? 1 : 0
  }, {after:function(){
    Admin.toast('已保存','ok');
    setTimeout(function(){ location.reload(); }, 600);
  }});
});
</script>
