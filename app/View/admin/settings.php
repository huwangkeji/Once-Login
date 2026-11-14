<?php
/**
 * 设置中心（后台）
 * 层级：模块 module → 分组 section → 设置项 key，分类大纲见 app/Core/SettingsSchema.php
 * @param array $v  base / title / notice / rows / group
 */
extract($v);
$rows     = $v['rows'] ?? [];
$activeMod = preg_replace('/[^a-z_]/', '', strtolower((string)($v['group'] ?? '')));

// 模块图标（内联 SVG path，与大纲顺序一致）
$icons = [
    'site'     => 'M12 2a15 15 0 1 0 0 30 15 15 0 0 0 0-30zm0 0c4 5 4 20 0 25M2.5 11h19M2.5 21h19',
    'account'  => 'M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z',
    'security' => 'M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z',
    'sso'      => 'M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7',
    'mail'     => 'M4 4h16v16H4zM4 6l8 6 8-6',
    'sms'      => 'M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z',
    'ucenter'  => 'M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75',
    'oauth'    => 'M15 3h4a2 2 0 0 1 2 2v4M9 21H5a2 2 0 0 1-2-2v-4M21 3l-7 7M3 21l7-7',
    'system'   => 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM19.4 15a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-2.7 1.1V21a2 2 0 1 1-4 0v-.1A1.6 1.6 0 0 0 7.5 19.4l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1A1.6 1.6 0 0 0 3 15H3a2 2 0 1 1 0-4h.1A1.6 1.6 0 0 0 4.6 7.5l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1A1.6 1.6 0 0 0 10 3.1V3a2 2 0 1 1 4 0v.1a1.6 1.6 0 0 0 2.7 1.1l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0 1.1 2.7H21a2 2 0 1 1 0 4h-.1a1.6 1.6 0 0 0-1.5 1.3z',
    '_other'   => 'M4 6h16M4 12h16M4 18h10',
];
// 模块对应的「高级/专用页面」（存在独立工具页时给出入口，避免重复维护）
$advPages = [
    'site'    => ['site',    '站点信息表单（含实时预览）'],
    'ucenter' => ['ucenter', 'UCenter 连接测试 / 通知自检'],
    'oauth'   => ['oauth',   '第三方登录平台配置向导'],
];

$schema    = SettingsSchema::schema();
$organized = SettingsSchema::organize($rows);
$others    = SettingsSchema::uncategorized($rows);

/** 统计模块内设置项数量 */
function _setCount($organized, $mid) {
    $n = 0;
    foreach (($organized[$mid] ?? []) as $sec) { $n += count($sec); }
    return $n;
}
/** 取值转为可展示的标量（数组→紧凑 JSON） */
function _setDisplay($val) {
    if (is_array($val))  return json_encode($val, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (is_bool($val))   return $val ? '1' : '0';
    return (string)$val;
}
$totalCount = 0;
foreach ($organized as $mid => $secs) { $totalCount += _setCount($organized, $mid); }
$totalCount += count($others);
?>
<div class="card">
  <div class="card-h between">
    <span>设置中心</span>
    <span class="faint" style="font-size:12.5px">
      共 <?= (int)$totalCount ?> 项 · 按功能模块分类 · 修改后即时生效
    </span>
  </div>
  <div class="card-b">
    <div class="set-search">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
      <input class="input" id="setSearch" placeholder="搜索设置项：名称 / 键名 / 说明（如「验证码」「ttl」「IP」）" autocomplete="off">
      <button class="btn btn-s" type="button" id="setSearchClear" style="display:none">清除</button>
      <span class="faint" id="setSearchInfo" style="font-size:12.5px;white-space:nowrap"></span>
    </div>

    <div class="set-console">
      <!-- 左侧：模块导航 -->
      <aside class="set-nav" id="setNav">
        <?php foreach ($schema as $mid => $mod): ?>
          <?php $cnt = _setCount($organized, $mid); if (!$cnt) continue; ?>
          <a class="set-nav__item<?= $activeMod === $mid ? ' is-active' : '' ?>"
             href="javascript:;" data-mod="<?= e($mid) ?>">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="<?= e($icons[$mid] ?? $icons['_other']) ?>"/></svg>
            <span class="set-nav__t"><?= e($mod['title']) ?></span>
            <span class="set-nav__n"><?= (int)$cnt ?></span>
          </a>
        <?php endforeach; ?>
        <?php if ($others): ?>
          <a class="set-nav__item" href="javascript:;" data-mod="_other">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="<?= e($icons['_other']) ?>"/></svg>
            <span class="set-nav__t">未分类</span>
            <span class="set-nav__n"><?= count($others) ?></span>
          </a>
        <?php endif; ?>
      </aside>

      <!-- 右侧：分组 / 设置项 -->
      <div class="set-main">
        <form id="setForm">
          <?php foreach ($schema as $mid => $mod): ?>
            <?php if (!_setCount($organized, $mid)) continue; ?>
            <section class="set-mod<?= $activeMod === $mid ? ' is-active' : '' ?>" data-mod="<?= e($mid) ?>">
              <header class="set-mod__h">
                <h3><?= e($mod['title']) ?></h3>
                <p><?= e($mod['desc']) ?></p>
                <?php if (isset($advPages[$mid])): ?>
                  <a class="btn btn-s" href="<?= e($base) ?>/admin.php?c=<?= e($advPages[$mid][0]) ?>">
                    高级：<?= e($advPages[$mid][1]) ?> →
                  </a>
                <?php endif; ?>
              </header>

              <?php foreach ($mod['sections'] as $sid => $sec): ?>
                <?php $keys = $organized[$mid][$sid] ?? []; if (!$keys) continue; ?>
                <div class="set-sec" data-sec="<?= e($sid) ?>">
                  <div class="set-sec__h">
                    <strong><?= e($sec['title']) ?></strong>
                    <?php if (!empty($sec['desc'])): ?>
                      <span class="faint" style="font-weight:400;font-size:12.5px"><?= e($sec['desc']) ?></span>
                    <?php endif; ?>
                  </div>
                  <div class="grid g2">
                    <?php foreach ($keys as $k => $item):
                      $def  = $item['def'];
                      $val  = $item['value'];
                      $type = $def['type'] ?? 'text';
                      // JSON 类字段用易读缩进，其余类型取标量（数组不会出现，但兜底避免 Array→string 告警）
                      $sval = $type === SettingsSchema::T_JSON ? _setDisplay($val) : (is_scalar($val) ? (string)$val : _setDisplay($val));
                      $hay  = strtolower($k . ' ' . ($def['label'] ?? '') . ' ' . ($def['help'] ?? '') . ' ' . ($def['keywords'] ?? '') . ' ' . $mod['title'] . ' ' . $sec['title']);
                    ?>
                      <div class="field set-field" data-search="<?= e($hay) ?>" style="margin-bottom:12px">
                        <label class="label" for="set_<?= e($k) ?>"><?= e($def['label'] ?? $k) ?></label>

                        <?php if ($type === SettingsSchema::T_BOOL): ?>
                          <select class="input" id="set_<?= e($k) ?>" data-k="<?= e($k) ?>">
                            <option value="1" <?= $sval === '1' || $val === 1 || $val === true ? 'selected' : '' ?>>开启</option>
                            <option value="0" <?= !($sval === '1' || $val === 1 || $val === true) ? 'selected' : '' ?>>关闭</option>
                          </select>

                        <?php elseif ($type === SettingsSchema::T_SELECT): ?>
                          <select class="input" id="set_<?= e($k) ?>" data-k="<?= e($k) ?>">
                            <?php foreach (($def['options'] ?? []) as $ov => $ol): ?>
                              <option value="<?= e((string)$ov) ?>" <?= $sval === (string)$ov ? 'selected' : '' ?>><?= e($ol) ?></option>
                            <?php endforeach; ?>
                          </select>

                        <?php elseif ($type === SettingsSchema::T_NUMBER): ?>
                          <div class="set-unit">
                            <input class="input mono" type="number" id="set_<?= e($k) ?>" data-k="<?= e($k) ?>" value="<?= e($sval) ?>">
                            <?php if (!empty($def['unit'])): ?><span class="set-unit__t"><?= e($def['unit']) ?></span><?php endif; ?>
                          </div>

                        <?php elseif ($type === SettingsSchema::T_TEXTAREA): ?>
                          <textarea class="input" rows="2" id="set_<?= e($k) ?>" data-k="<?= e($k) ?>"><?= e($sval) ?></textarea>

                        <?php elseif ($type === SettingsSchema::T_JSON): ?>
                          <textarea class="input mono set-json" rows="3" spellcheck="false" id="set_<?= e($k) ?>" data-k="<?= e($k) ?>"><?= e(_setDisplay($val)) ?></textarea>

                        <?php elseif ($type === SettingsSchema::T_PASSWORD): ?>
                          <div class="set-secret">
                            <input class="input mono" type="password" id="set_<?= e($k) ?>" data-k="<?= e($k) ?>" value="<?= e($sval) ?>" autocomplete="new-password" spellcheck="false">
                            <button class="btn btn-s" type="button" data-toggle-pw="set_<?= e($k) ?>">显示</button>
                          </div>

                        <?php elseif ($type === SettingsSchema::T_STATIC): ?>
                          <input class="input mono" value="<?= e($sval) ?>" readonly disabled>

                        <?php else: ?>
                          <input class="input" id="set_<?= e($k) ?>" data-k="<?= e($k) ?>" value="<?= e($sval) ?>" autocomplete="off">
                        <?php endif; ?>

                        <?php if (!empty($def['help'])): ?>
                          <div class="hint"><?= e($def['help']) ?></div>
                        <?php endif; ?>
                        <div class="hint">
                          键名：<span class="mono faint"><?= e($k) ?></span>
                          <?php if (!$item['in_db']): ?><span class="badge badge-info" style="margin-left:6px">未入库·默认值</span><?php endif; ?>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </section>
          <?php endforeach; ?>

          <?php if ($others): ?>
            <section class="set-mod" data-mod="_other">
              <header class="set-mod__h">
                <h3>未分类</h3>
                <p>大纲尚未收录的历史配置项，可正常编辑；建议收录到 app/Core/SettingsSchema.php 后归入对应模块。</p>
              </header>
              <div class="set-sec" data-sec="other">
                <div class="set-sec__h"><strong>历史 / 自定义键值</strong></div>
                <div class="grid g2">
                  <?php foreach ($others as $r):
                    $k = $r['s_key'];
                    $raw = (string)$r['s_value'];
                    $d = json_decode($raw, true);
                    $val = ($d === null && $raw !== 'null') ? $raw : $d;
                    $isJson = is_array($val);
                  ?>
                    <div class="field set-field" data-search="<?= e(strtolower($k . ' ' . ($r['remark'] ?? ''))) ?>" style="margin-bottom:12px">
                      <label class="label" for="set_<?= e($k) ?>"><?= e($r['remark'] ?: $k) ?></label>
                      <?php if ($isJson): ?>
                        <textarea class="input mono set-json" rows="2" id="set_<?= e($k) ?>" data-k="<?= e($k) ?>"><?= e(_setDisplay($val)) ?></textarea>
                      <?php else: ?>
                        <input class="input" id="set_<?= e($k) ?>" data-k="<?= e($k) ?>" value="<?= e((string)$val) ?>" autocomplete="off">
                      <?php endif; ?>
                      <div class="hint">键名：<span class="mono faint"><?= e($k) ?></span></div>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            </section>
          <?php endif; ?>

          <div class="set-bar">
            <span class="faint" id="setDirty" style="font-size:12.5px">尚未修改</span>
            <span class="row">
              <button class="btn" type="button" onclick="location.reload()">放弃修改</button>
              <button class="btn btn-p" type="submit">保存设置</button>
            </span>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  var nav  = document.getElementById('setNav');
  var mods = Array.prototype.slice.call(document.querySelectorAll('.set-mod'));
  var navItems = Array.prototype.slice.call(document.querySelectorAll('.set-nav__item'));
  var search = document.getElementById('setSearch');
  var info   = document.getElementById('setSearchInfo');
  var clearB = document.getElementById('setSearchClear');

  var current = '';
  function activate(mid){
    if (!mid) return;
    current = mid;
    mods.forEach(function(m){ m.classList.toggle('is-active', m.getAttribute('data-mod') === mid); });
    navItems.forEach(function(a){ a.classList.toggle('is-active', a.getAttribute('data-mod') === mid); });
    try { if (history.replaceState) history.replaceState(null, '', '?c=settings&group=' + encodeURIComponent(mid)); } catch(e){}
  }
  navItems.forEach(function(a){
    a.addEventListener('click', function(){ activate(a.getAttribute('data-mod')); });
  });
  // 默认激活：URL 指定的模块优先，否则第一个模块
  var first = mods.length ? mods[0].getAttribute('data-mod') : '';
  var preActive = null;
  for (var i = 0; i < mods.length; i++) {
    if (mods[i].classList.contains('is-active')) { preActive = mods[i].getAttribute('data-mod'); break; }
  }
  activate(preActive || first);

  // ---------- 搜索：命中即展开，未命中隐藏 ----------
  function doSearch(){
    var q = (search.value || '').trim().toLowerCase();
    clearB.style.display = q ? '' : 'none';
    if (!q) {
      document.querySelectorAll('.set-field').forEach(function(f){ f.style.display = ''; });
      document.querySelectorAll('.set-sec').forEach(function(s){ s.style.display = ''; });
      mods.forEach(function(m){ m.style.display = ''; });
      navItems.forEach(function(a){ a.style.display = ''; });
      info.textContent = '';
      activate(current || first);
      return;
    }
    var hit = 0, modHit = {};
    document.querySelectorAll('.set-field').forEach(function(f){
      var ok = (f.getAttribute('data-search') || '').indexOf(q) > -1;
      f.style.display = ok ? '' : 'none';
      if (ok) { hit++; var m = f.closest('.set-mod'); if (m) modHit[m.getAttribute('data-mod')] = (modHit[m.getAttribute('data-mod')] || 0) + 1; }
    });
    document.querySelectorAll('.set-sec').forEach(function(s){
      s.style.display = s.querySelector('.set-field:not([style*="display: none"])') ? '' : 'none';
    });
    mods.forEach(function(m){
      var ok = !!modHit[m.getAttribute('data-mod')];
      m.style.display = ok ? '' : 'none';
      m.classList.toggle('is-active', ok);
    });
    navItems.forEach(function(a){
      a.style.display = modHit[a.getAttribute('data-mod')] ? '' : 'none';
    });
    info.textContent = hit ? ('匹配 ' + hit + ' 项') : '无匹配项';
  }
  var timer = null;
  search.addEventListener('input', function(){
    clearTimeout(timer); timer = setTimeout(doSearch, 150);
  });
  clearB.addEventListener('click', function(){ search.value = ''; doSearch(); search.focus(); });

  // ---------- 敏感字段：显示 / 隐藏 ----------
  document.querySelectorAll('[data-toggle-pw]').forEach(function(btn){
    btn.addEventListener('click', function(){
      var inp = document.getElementById(btn.getAttribute('data-toggle-pw'));
      if (!inp) return;
      var show = inp.type === 'password';
      inp.type = show ? 'text' : 'password';
      btn.textContent = show ? '隐藏' : '显示';
    });
  });

  // ---------- 变更追踪 ----------
  var dirty = {};
  var count = 0;
  function refreshDirty(){
    var n = Object.keys(dirty).length;
    document.getElementById('setDirty').textContent = n ? ('已修改 ' + n + ' 项，未保存') : '尚未修改';
    document.getElementById('setDirty').style.color = n ? 'var(--warn)' : '';
  }
  document.querySelectorAll('#setForm [data-k]').forEach(function(inp){
    inp.addEventListener('change', function(){
      if (inp.dataset.init === undefined) inp.dataset.init = inp.value;
      if (inp.value !== inp.dataset.init) dirty[inp.getAttribute('data-k')] = 1;
      else delete dirty[inp.getAttribute('data-k')];
      refreshDirty();
    });
    if (inp.dataset.init === undefined) inp.dataset.init = inp.value;
  });

  // ---------- 保存 ----------
  document.getElementById('setForm').addEventListener('submit', function(ev){
    ev.preventDefault();
    var data = {};
    var inputs = this.querySelectorAll('[data-k]');
    inputs.forEach(function(inp){
      var el = inp;
      // 被搜索隐藏的模块同样提交，避免漏存
      data[el.getAttribute('data-k')] = (el.value || '').trim();
    });
    Admin.req('admin','settings_save',{ data: JSON.stringify(data) },{
      okMsg:false,
      after:function(res){
        Admin.toast('设置已保存，即将刷新', 'ok');
        setTimeout(function(){ location.reload(); }, 500);
      }
    });
  });
  refreshDirty();
})();
</script>

<style>
.set-search{display:flex;align-items:center;gap:8px;margin-bottom:14px}
.set-search .input{flex:1}
.set-console{display:grid;grid-template-columns:210px 1fr;gap:18px;align-items:start}
.set-nav{position:sticky;top:12px;display:flex;flex-direction:column;gap:2px;
  background:var(--bg-soft);border:1px solid var(--border);border-radius:var(--radius-s);padding:6px}
.set-nav__item{display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:6px;
  color:var(--muted);font-size:13px;text-decoration:none;transition:all .15s}
.set-nav__item:hover{background:#fff;color:var(--text)}
.set-nav__item.is-active{background:var(--primary);color:#fff;box-shadow:0 2px 8px rgba(37,99,235,.22)}
.set-nav__item.is-active svg{color:#fff}
.set-nav__t{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.set-nav__n{font-size:11.5px;padding:1px 6px;border-radius:8px;background:rgba(15,34,57,.06);color:var(--faint)}
.set-nav__item.is-active .set-nav__n{background:rgba(255,255,255,.22);color:#fff}
.set-mod{display:none}
.set-mod.is-active{display:block;animation:vcFadeIn .25s ease both}
.set-mod__h{margin-bottom:14px}
.set-mod__h h3{margin:0 0 4px;font-size:16px}
.set-mod__h p{margin:0 0 8px;font-size:12.5px;color:var(--muted)}
.set-sec{margin-bottom:18px;border:1px solid var(--border);border-radius:var(--radius-s);padding:14px 16px 4px;background:var(--panel)}
.set-sec__h{display:flex;align-items:baseline;gap:10px;margin-bottom:12px;
  border-bottom:1px dashed var(--border);padding-bottom:6px}
.set-sec__h strong{font-size:13.5px}
.set-unit{display:flex;align-items:center;gap:8px}
.set-unit__t{font-size:12.5px;color:var(--muted);white-space:nowrap}
.set-secret{display:flex;align-items:center;gap:8px}
.set-secret .input{flex:1;min-width:0}
.set-json{font-size:12.5px;line-height:1.6}
.set-bar{position:sticky;bottom:0;display:flex;align-items:center;justify-content:space-between;gap:10px;
  padding:12px 14px;margin-top:6px;background:var(--panel);
  border:1px solid var(--border);border-radius:var(--radius-s);box-shadow:var(--shadow-sm)}
@media (max-width:900px){
  .set-console{grid-template-columns:1fr}
  .set-nav{position:static;flex-direction:row;overflow-x:auto;gap:6px}
  .set-nav__item{white-space:nowrap}
  .set-search{flex-wrap:wrap}
}
@media (max-width:480px){
  .set-sec{padding:12px 12px 2px}
  .grid.g2{grid-template-columns:1fr}
}
</style>
