<?php extract($v); $packages = $v['packages'];
$fmtSize = function ($n) {
    $n = intval($n);
    if ($n >= 1048576) return round($n / 1048576, 2) . ' MB';
    if ($n >= 1024) return round($n / 1024, 1) . ' KB';
    return $n . ' B';
};
?>
<div class="card" style="margin-bottom:14px">
  <div class="card-h between">
    <span>上传 SDK 压缩包</span>
    <span class="faint" style="font-weight:400;font-size:12.5px">仅 .zip，单个 ≤ 50MB，含压缩炸弹防护，上传即同步到前端下载中心</span>
  </div>
  <div class="card-b">
    <form id="sdkForm" enctype="multipart/form-data">
      <div class="grid g2">
        <div class="field" style="margin-bottom:10px">
          <label class="label" for="file">压缩包文件 *</label>
          <input class="input" type="file" id="file" name="file" accept=".zip,application/zip" required>
          <div class="hint">推荐命名：UIC_OpenAPI_v1.1.0.zip、UIC_SDK_v1.1.0.zip 等</div>
        </div>
        <div class="field" style="margin-bottom:10px">
          <label class="label" for="title">展示名称</label>
          <input class="input" id="title" name="title" placeholder="例如：UIC SDK 多语言客户端">
        </div>
        <div class="field" style="margin-bottom:10px">
          <label class="label" for="version">版本号</label>
          <input class="input" id="version" name="version" placeholder="例如：v1.1.0">
        </div>
        <div class="field" style="margin-bottom:10px">
          <label class="label" for="desc">简介</label>
          <input class="input" id="desc" name="desc" placeholder="简要说明适用范围（选填）">
        </div>
      </div>
      <div class="row">
        <button class="btn btn-p" type="submit">上传压缩包</button>
        <button class="btn" type="reset">清空</button>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-h between">
    <span>SDK 压缩包列表 <span class="faint" style="font-weight:400;font-size:12.5px">共 <?= count($packages) ?> 个</span></span>
    <span class="faint" style="font-weight:400;font-size:12.5px">前端下载中心：开放平台 → 开放文档 → SDK 下载中心</span>
  </div>
  <div class="card-b tight t-scroll">
    <?php if (empty($packages)): ?><div class="empty">还没有任何 SDK 压缩包，请先上传</div><?php else: ?>
    <table class="table">
      <thead><tr>
        <th>文件名</th><th>版本</th><th>简介</th><th>大小</th><th>上传时间</th><th>来源</th><th class="right">操作</th>
      </tr></thead>
      <tbody>
      <?php foreach ($packages as $p): ?>
        <tr>
          <td>
            <b><?= e($p['name']) ?></b>
            <div class="faint" style="font-size:12px"><?= e($p['title']) ?></div>
          </td>
          <td><span class="badge badge-info"><?= e($p['version']) ?></span></td>
          <td style="max-width:260px;font-size:12px"><?= e($p['desc']) ?: '<span class="faint">-</span>' ?></td>
          <td><?= $fmtSize($p['size']) ?></td>
          <td class="faint" style="font-size:12px"><?= date('Y-m-d H:i', intval($p['mtime'])) ?></td>
          <td><?= $p['builtin'] ? '<span class="badge badge-mute">内置</span>' : '<span class="badge badge-ok">上传</span>' ?></td>
          <td class="right">
            <button class="btn btn-s" onclick="Sdks.del('<?= e($p['name']) ?>')" <?= $p['builtin'] ? 'disabled title="内置包不可删除"' : '' ?>>删除</button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<script>
var Sdks = {
  del: function(name){
    if (!confirm('确定删除压缩包「' + name + '」？前端下载中心将同步移除。')) return;
    Admin.req('sdkpkg','delete',{name:name},{after:function(){ setTimeout(function(){ location.reload(); }, 400); }});
  }
};
document.getElementById('sdkForm').addEventListener('submit', function(ev){
  ev.preventDefault();
  var fd = new FormData();
  fd.append('m','sdkpkg'); fd.append('a','upload');
  fd.append('file', document.getElementById('file').files[0]);
  fd.append('title', document.getElementById('title').value.trim());
  fd.append('version', document.getElementById('version').value.trim());
  fd.append('desc', document.getElementById('desc').value.trim());
  fd.append('X-Requested-With','XMLHttpRequest');
  Admin.toast('正在上传与校验…','ok');
  fetch(Admin.API, { method:'POST', body: fd, credentials:'same-origin',
    headers:{'X-Requested-With':'XMLHttpRequest'}
  }).then(function(r){ return r.json(); }).then(function(res){
    if (res.code !== 0) { Admin.toast(res.message, 'err'); return; }
    Admin.toast(res.message || '上传成功', 'ok');
    setTimeout(function(){ location.reload(); }, 600);
  }).catch(function(e){ Admin.toast('网络异常：' + e.message, 'err'); });
});
</script>