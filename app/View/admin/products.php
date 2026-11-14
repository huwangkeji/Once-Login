<?php extract($v); $products = $v['products'] ?? []; ?>
<div class="card">
  <div class="card-h between">
    <span>产品展示管理</span>
    <button class="btn btn-s btn-p" onclick="ProductMgr.showAdd()">+ 添加产品</button>
  </div>
  <div class="card-b">
    <p class="muted" style="margin-bottom:14px">配置会员中心侧边栏展示的产品链接。可设置产品名称、跳转链接、自定义展示信息、图标和排序。</p>

    <div id="productList">
      <?php if (empty($products)): ?>
        <div class="empty">暂无产品，点击右上角「添加产品」创建</div>
      <?php else: ?>
      <table class="table compact">
        <thead><tr><th>排序</th><th>图标</th><th>名称</th><th>描述</th><th>链接</th><th>状态</th><th class="right">操作</th></tr></thead>
        <tbody>
        <?php foreach ($products as $p): ?>
          <tr data-id="<?= intval($p['id']) ?>">
            <td><?= intval($p['sort_order']) ?></td>
            <td><span class="badge badge-mute"><?= e($p['icon']) ?></span></td>
            <td><b><?= e($p['name']) ?></b></td>
            <td class="muted"><?= e($p['description']) ?></td>
            <td class="mono" style="max-width:200px;overflow:hidden;text-overflow:ellipsis"><?= e($p['url']) ?></td>
            <td><?= intval($p['enabled']) ? '<span class="badge badge-ok">启用</span>' : '<span class="badge badge-mute">禁用</span>' ?></td>
            <td class="right">
              <button class="btn btn-s" onclick="ProductMgr.showEdit(<?= intval($p['id']) ?>)">编辑</button>
              <button class="btn btn-s btn-d" onclick="ProductMgr.del(<?= intval($p['id']) ?>)">删除</button>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- 编辑弹窗 -->
<div class="modal" id="productModal" style="display:none">
  <div class="modal-bg" onclick="ProductMgr.hide()"></div>
  <div class="modal-box">
    <div class="modal-h">
      <span id="modalTitle">添加产品</span>
      <button class="modal-close" onclick="ProductMgr.hide()">×</button>
    </div>
    <div class="modal-b">
      <input type="hidden" id="pid" value="0">
      <div class="field">
        <label class="label">产品名称 <span class="req">*</span></label>
        <input class="input" id="pname" maxlength="100" placeholder="如：热铁盒网盘">
      </div>
      <div class="field">
        <label class="label">跳转链接 <span class="req">*</span></label>
        <input class="input" id="purl" maxlength="500" placeholder="https://...">
      </div>
      <div class="field">
        <label class="label">自定义展示信息</label>
        <input class="input" id="pdesc" maxlength="200" placeholder="副标题/描述（选填）">
      </div>
      <div class="grid g2">
        <div class="field">
          <label class="label">图标</label>
          <select class="input" id="picon">
            <?php foreach (['link'=>'链接','doc'=>'文档','chat'=>'聊天','store'=>'商店','tool'=>'工具','cloud'=>'云服务','mail'=>'邮件','shield'=>'安全','star'=>'收藏','heart'=>'关注','globe'=>'全球','code'=>'代码'] as $k => $t): ?>
              <option value="<?= e($k) ?>"><?= e($t) ?>（<?= e($k) ?>）</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label class="label">排序</label>
          <input class="input" id="psort" type="number" value="0" placeholder="越小越靠前">
        </div>
      </div>
      <div class="field">
        <label class="check"><input type="checkbox" id="penabled" checked> 启用</label>
      </div>
    </div>
    <div class="modal-f">
      <button class="btn" onclick="ProductMgr.hide()">取消</button>
      <button class="btn btn-p" onclick="ProductMgr.save()">保存</button>
    </div>
  </div>
</div>

<style>
.req{color:#dc2626}
.check{display:flex;align-items:center;gap:6px;font-size:14px;cursor:pointer}
.check input{width:16px;height:16px;accent-color:#2563eb}
</style>

<script>
var ProductMgr = (function(){
  var products = <?= json_encode($products, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

  function showAdd(){
    document.getElementById('modalTitle').textContent = '添加产品';
    document.getElementById('pid').value = 0;
    document.getElementById('pname').value = '';
    document.getElementById('purl').value = '';
    document.getElementById('pdesc').value = '';
    document.getElementById('picon').value = 'link';
    document.getElementById('psort').value = 0;
    document.getElementById('penabled').checked = true;
    document.getElementById('productModal').style.display = '';
  }

  function showEdit(id){
    var p = products.find(function(x){ return parseInt(x.id) === id; });
    if (!p) return;
    document.getElementById('modalTitle').textContent = '编辑产品';
    document.getElementById('pid').value = p.id;
    document.getElementById('pname').value = p.name;
    document.getElementById('purl').value = p.url;
    document.getElementById('pdesc').value = p.description;
    document.getElementById('picon').value = p.icon;
    document.getElementById('psort').value = p.sort_order;
    document.getElementById('penabled').checked = parseInt(p.enabled) === 1;
    document.getElementById('productModal').style.display = '';
  }

  function hide(){
    document.getElementById('productModal').style.display = 'none';
  }

  function save(){
    var id = parseInt(document.getElementById('pid').value);
    var d = {
      name: document.getElementById('pname').value.trim(),
      url: document.getElementById('purl').value.trim(),
      description: document.getElementById('pdesc').value.trim(),
      icon: document.getElementById('picon').value,
      sort_order: document.getElementById('psort').value,
      enabled: document.getElementById('penabled').checked ? 1 : 0
    };
    if (!d.name) { Admin.toast('请输入产品名称', 'err'); return; }
    if (!d.url) { Admin.toast('请输入跳转链接', 'err'); return; }
    var action = id > 0 ? 'product_update' : 'product_create';
    if (id > 0) d.id = id;
    Admin.req('admin', action, d, {okMsg:false}).then(function(res){
      if (res.code !== 0) return;
      Admin.toast('保存成功', 'ok');
      setTimeout(function(){ location.reload(); }, 600);
    });
  }

  function del(id){
    Admin.confirmDo('确定删除该产品？', function(){
      Admin.req('admin', 'product_delete', {id: id}, {okMsg:false}).then(function(res){
        if (res.code !== 0) return;
        Admin.toast('已删除', 'ok');
        setTimeout(function(){ location.reload(); }, 600);
      });
    });
  }

  return { showAdd: showAdd, showEdit: showEdit, hide: hide, save: save, del: del };
})();
</script>
