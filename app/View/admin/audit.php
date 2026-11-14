<?php extract($v); ?>
<div class="card accent" style="margin-bottom:14px">
  <div class="card-h between">
    <span><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg> 安全审计中心</span>
    <span class="faint" style="font-size:12.5px">数据来源：系统审计日志（warn / error 级），实时统计</span>
  </div>
</div>

<div class="grid g4" style="margin-bottom:14px">
  <div class="stat stat-err"><div class="stat-v" id="stFail">-</div><div class="stat-l">今日登录失败 / 风控拦截</div></div>
  <div class="stat stat-warn"><div class="stat-v" id="stLocked">-</div><div class="stat-l">当前锁定账号</div></div>
  <div class="stat"><div class="stat-v" id="stUc">-</div><div class="stat-l">今日 UCenter 调用失败</div></div>
  <div class="stat stat-ok"><div class="stat-v" id="stOps">-</div><div class="stat-l">今日管理员操作</div></div>
  <div class="stat"><div class="stat-v" id="stLogs">-</div><div class="stat-l">日志总量</div></div>
  <div class="stat stat-err"><div class="stat-v" id="stErr">-</div><div class="stat-l">error 级日志数</div></div>
  <div class="stat stat-ok"><div class="stat-v" id="stLogin">-</div><div class="stat-l">今日成功登录</div></div>
  <div class="stat"><div class="stat-v" id="stTotal">-</div><div class="stat-l">今日合计事件</div></div>
</div>

<div class="grid g2" style="margin-bottom:14px">
  <div class="card">
    <div class="card-h">今日告警按类型分布</div>
    <div class="card-b" id="warnTypes"><div class="empty">加载中…</div></div>
  </div>
  <div class="card">
    <div class="card-h">近 7 天登录 / 失败趋势</div>
    <div class="card-b" id="trendBox"><div class="empty">加载中…</div></div>
  </div>
</div>

<div class="card">
  <div class="card-h between">
    <span>最新安全告警</span>
    <button class="btn btn-s" id="auditRefresh" type="button">刷新</button>
  </div>
  <div class="card-b tight">
    <div class="t-scroll">
      <table class="table">
        <thead><tr><th>时间</th><th>级别</th><th>类型</th><th>账户 / 目标</th><th>描述</th></tr></thead>
        <tbody id="warnBody"><tr><td colspan="5" class="empty">加载中…</td></tr></tbody>
      </table>
    </div>
  </div>
</div>

<script>
(function(){
  function esc(s){ return Admin.esc(s); }
  function lv(level){
    return level === 'error' ? '<span class="badge badge-err">error</span>'
      : level === 'warn' ? '<span class="badge badge-warn">warn</span>'
      : '<span class="badge badge-mute">' + esc(level) + '</span>';
  }
  function load(){
    Admin.req('admin','audit_stats',{},{okMsg:false}).then(function(res){
      if (res.code !== 0) return;
      var d = res.data, s = d.summary;
      document.getElementById('stFail').textContent  = s.fail_today;
      document.getElementById('stLocked').textContent = s.locked_now;
      document.getElementById('stUc').textContent    = s.uc_fail;
      document.getElementById('stOps').textContent   = s.admin_ops;
      document.getElementById('stLogs').textContent  = s.total_logs;
      document.getElementById('stErr').textContent   = s.error_level;
      document.getElementById('stLogin').textContent = s.today_login;
      document.getElementById('stTotal').textContent = s.fail_today + s.admin_ops + s.today_login;

      // 告警类型分布
      var wt = document.getElementById('warnTypes');
      if (!d.warn_types.length) { wt.innerHTML = '<div class="empty">今日无任何告警，风控状态良好</div>'; }
      else {
        var max = Math.max.apply(null, d.warn_types.map(function(x){ return x.c; }));
        wt.innerHTML = d.warn_types.map(function(x){
          var pct = max ? Math.max(4, Math.round(x.c / max * 100)) : 0;
          var danger = ['login','admin'].indexOf(x.type) >= 0;
          return '<div class="bar-row' + (danger ? ' danger' : '') + '">'
            + '<span class="bar-name" title="' + esc(x.type) + '">' + esc(x.type) + '</span>'
            + '<div class="bar-track"><div class="bar-fill" style="width:' + pct + '%"></div></div>'
            + '<span class="bar-vals">' + x.c + ' 条</span></div>';
        }).join('');
      }

      // 7 天趋势（登录 vs 失败 双条）
      var tr = document.getElementById('trendBox');
      tr.innerHTML = d.trend.map(function(t){
        var maxV = Math.max(1, Math.max(t.login, t.fail));
        var lp = Math.round(t.login / maxV * 100), fp = Math.round(t.fail / maxV * 100);
        return '<div>' + t.date + '</div>'
          + '<div class="bar-row"><span class="bar-name">登录</span><div class="bar-track"><div class="bar-fill" style="width:' + lp + '%"></div></div><span class="bar-vals">' + t.login + '</span></div>'
          + '<div class="bar-row danger"><span class="bar-name">失败</span><div class="bar-track"><div class="bar-fill" style="width:' + fp + '%"></div></div><span class="bar-vals">' + t.fail + '</span></div>';
      }).join('');

      // 最新告警表
      var body = document.getElementById('warnBody');
      if (!d.recent.length) { body.innerHTML = '<tr><td colspan="5" class="empty">暂无告警记录</td></tr>'; return; }
      body.innerHTML = d.recent.map(function(r){
        var extra = r.extra && r.extra.length ? '<div class="faint" style="font-size:11.5px">' + esc(JSON.stringify(r.extra)) + '</div>' : '';
        return '<tr><td class="nowrap">' + new Date(r.created_at * 1000).toLocaleString('zh-CN', {hour12:false}) + '</td>'
          + '<td>' + lv(r.level) + '</td>'
          + '<td><span class="mono">' + esc(r.type) + '</span></td>'
          + '<td>' + esc(r.target || '-') + '</td>'
          + '<td>' + esc(r.message) + extra + '</td></tr>';
      }).join('');
    });
  }
  document.getElementById('auditRefresh').onclick = load;
  load();
})();
</script>