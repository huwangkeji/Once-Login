<?php extract($v); ?>
<div class="card accent" style="margin-bottom:14px">
  <div class="card-h between">
    <span><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg> 在线的用户会话</span>
    <div class="row" style="gap:8px">
      <input class="input" id="sessKw" placeholder="搜索用户名 / IP / 设备" style="width:220px">
      <button class="btn btn-p btn-s" id="sessSearch" type="button">搜索</button>
      <button class="btn btn-s" id="sessRefresh" type="button">刷新</button>
    </div>
  </div>
  <div class="card-b tight">
    <div class="t-scroll">
      <table class="table">
        <thead>
          <tr>
            <th>用户</th><th>设备</th><th>登录 IP</th><th>最近活跃</th><th>登录时间</th><th>有效期</th><th>状态</th><th>操作</th>
          </tr>
        </thead>
        <tbody id="sessBody"><tr><td colspan="8" class="empty">加载中…</td></tr></tbody>
      </table>
    </div>
    <div class="pager" id="sessPager" style="padding:0 16px 14px"></div>
  </div>
</div>

<script>
(function(){
  var page = 1, size = 20, kw = '';
  function esc(s){ return Admin.esc(s); }
  function fmt(ts){ return ts ? new Date(ts * 1000).toLocaleString('zh-CN', {hour12:false}) : '-'; }
  function load(){
    var body = document.getElementById('sessBody');
    body.innerHTML = '<tr><td colspan="8" class="empty">加载中…</td></tr>';
    Admin.req('admin','session_list',{kw:kw,page:page,size:size},{okMsg:false}).then(function(res){
      if (res.code !== 0) return;
      var d = res.data;
      if (!d.rows.length) { body.innerHTML = '<tr><td colspan="8" class="empty">暂无在线会话</td></tr>'; document.getElementById('sessPager').innerHTML=''; return; }
      body.innerHTML = d.rows.map(function(r){
        var ua = esc(r.ua_short || r.device_label || '-');
        var marked = r.is_current ? '<span class="badge badge-info">当前浏览器</span>' : '';
        var remember = r.remember ? '<span class="badge badge-mute">记住</span>' : '';
        return '<tr>'
          + '<td><b>' + esc(r.username || ('uid:' + r.uid)) + '</b>' + (r.nickname ? '<div class="faint" style="font-size:12px">' + esc(r.nickname) + '</div>' : '') + '</td>'
          + '<td title="' + ua + '">' + (r.device_label ? esc(r.device_label) : '<span class="faint">浏览器</span>') + '</td>'
          + '<td><span class="mono">' + esc(r.login_ip || '-') + '</span></td>'
          + '<td>' + r.last_text + '</td>'
          + '<td>' + fmt(r.login_at) + '</td>'
          + '<td>' + r.expires_text + '</td>'
          + '<td><span class="badge badge-ok">在线</span> ' + marked + ' ' + remember + '</td>'
          + '<td class="nowrap">'
          +   (r.is_current ? '' : '<button class="btn btn-s" data-act="kill" data-sid="' + esc(r.sid) + '">下线</button> ')
          +   '<button class="btn btn-s btn-d" data-act="kick" data-uid="' + r.uid + '">踢出用户</button>'
          + '</td></tr>';
      }).join('');
      var totalPages = Math.max(1, Math.ceil(d.total / d.size));
      var p = ['<span class="faint">共 ' + d.total + ' 条</span>'];
      if (page > 1) p.push('<button class="btn btn-s" id="pgPrev">上一页</button>');
      p.push('<span class="mono">' + page + ' / ' + totalPages + '</span>');
      if (page < totalPages) p.push('<button class="btn btn-s" id="pgNext">下一页</button>');
      document.getElementById('sessPager').innerHTML = p.join('');
      if (document.getElementById('pgPrev')) document.getElementById('pgPrev').onclick = function(){ page--; load(); };
      if (document.getElementById('pgNext')) document.getElementById('pgNext').onclick = function(){ page++; load(); };
    });
  }
  document.getElementById('sessBody').addEventListener('click', function(ev){
    var btn = ev.target.closest('button[data-act]');
    if (!btn) return;
    var act = btn.getAttribute('data-act');
    if (act === 'kill') {
      if (!confirm('确认强制下线该会话？用户将立即退出登录。')) return;
      Admin.req('admin','session_kill',{sid:btn.getAttribute('data-sid')},{okMsg:false,after:function(){ load(); }});
    } else {
      if (!confirm('确认将该用户全部设备强制下线？')) return;
      Admin.req('admin','session_kick_user',{uid:btn.getAttribute('data-uid')},{okMsg:false,after:function(){ load(); }});
    }
  });
  document.getElementById('sessSearch').onclick = function(){ kw = document.getElementById('sessKw').value.trim(); page = 1; load(); };
  document.getElementById('sessRefresh').onclick = function(){ load(); };
  document.getElementById('sessKw').addEventListener('keydown', function(e){ if (e.key === 'Enter') document.getElementById('sessSearch').click(); });
  load();
})();
</script>