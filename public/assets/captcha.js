/**
 * 统一身份认证中心 · 人机验证前端模块（前台 / 后台共用）
 * 支持样式：
 *   slide   滑动拼图（range 滑块驱动，桌面/移动指针事件兼容）
 *   digits  纯数字 4 位 / mixed 数字+字母
 *   click   文字点选（按提示顺序点击）
 *   icon    图标选择（点选全部指定图标）
 *   passive 无感验证（行为采集 + 服务端评分，未通过自动回退 fallback 样式）
 *
 * 依赖：页面需已引入 app.css（提供 .verify-* 企业级样式），
 *      并提供 public/assets/captcha.js 所在域的 App / Admin 全局请求对象。
 * DOM 结构见 app/View/partials/captcha_card.php（#capModeText/#capModeSlide/#capModePick/#capModePassive 等）。
 *
 * 用法：
 *   <script src="/assets/captcha.js"></script>
 *   UicCaptcha.init({ req: App.req.bind(App), show: true, style: 'slide', fallback: 'slide' });
 *   // init 选项：req(必需，惰性函数) api(可选，无感验证预检直连地址) show style fallback active
 *   // 提交时取：
 *   //   UicCaptcha.token     当前验证码 token（字符串）
 *   //   UicCaptcha.answer()  当前用户输入 / 滑块位置 / 点选下标 / 行为 JSON
 *   //   UicCaptcha.ready()   是否已完成验证（可提交）
 *   //   UicCaptcha.reset()   刷新验证码（校验失败后调用）
 *   //   UicCaptcha.mode      当前实际模式（slide/text/pick/passive）
 */
(function (global) {
  'use strict';

  var S = {
    req: null,
    api: '',        // 可选：API 地址（提供后无感验证预检走直连，避免通用 req 的失败 toast 打扰用户）
    show: false,
    style: 'slide',      // 后台配置的默认样式
    fallback: 'slide',   // 无感验证回退样式
    active: function () { return true; },
    token: '',
    mode: 'slide',       // 当前实际渲染的模式：text/slide/pick/passive
    curStyle: 'slide',   // 当前实际使用的样式（无感回退后与 S.style 不同，刷新时保持）
    natW: 300,
    natH: 150,
    els: {},
    // click / icon 状态
    pickOrdered: false,  // click=按顺序；icon=不计序
    pickLimit: 0,
    picked: [],
    // passive 状态
    passive: null,
    passiveTimer: null,
    passiveCfg: {},
    blocked: false,
    loaded: false
  };

  function $(id) { return document.getElementById(id); }

  function cache() {
    var e = S.els;
    e.modeText     = $('capModeText');
    e.modeSlide    = $('capModeSlide');
    e.modePick     = $('capModePick');
    e.modePassive  = $('capModePassive');
    e.capImg       = $('capImg');
    e.capImgOverlay = $('capImgOverlay');
    e.capBg        = $('capBg');
    e.capBgOverlay = $('capBgOverlay');
    e.capPiece     = $('capPiece');
    e.capStage     = $('capStage');
    e.capRange     = $('capRange');
    e.capFill      = $('capFill');
    e.capThumb     = $('capThumb');
    e.capSlider    = $('capSlider');
    e.capHint      = $('capHint');
    e.answer       = $('captcha_answer');
    e.pickImg      = $('capPickImg');
    e.pickGrid     = $('capPickGrid');
    e.pickOverlay  = $('capPickOverlay');
    e.prompt       = $('capPrompt');
    e.passiveText  = $('capPassiveText');
    e.passiveSpin  = $('capPassiveSpin');
    e.refreshBtn   = $('capRefresh');
    e.footHint     = $('capFootHint');
    e.toast        = $('capToast');
    e.card         = document.querySelector('.verify-card');
  }

  // ---------- 模式切换 ----------

  function modeOf(style) {
    if (style === 'slide') return 'slide';
    if (style === 'click' || style === 'icon') return 'pick';
    if (style === 'passive') return 'passive';
    return 'text'; // digits / mixed
  }

  function setMode(style) {
    if (!S.show) return;
    var m = modeOf(style);
    S.mode = m;
    var map = { text: 'modeText', slide: 'modeSlide', pick: 'modePick', passive: 'modePassive' };
    for (var k in map) {
      var el = S.els[map[k]];
      if (!el) continue;
      if (k === m) { el.classList.remove('hide'); el.style.display = ''; }
      else { el.classList.add('hide'); el.style.display = 'none'; }
    }
  }

  // ---------- 加载态 ----------

  function showLoading() {
    var ov = S.mode === 'slide' ? S.els.capBgOverlay
           : S.mode === 'pick'  ? S.els.pickOverlay
           : S.els.capImgOverlay;
    if (ov) ov.style.display = 'flex';
  }
  function hideLoading() {
    [S.els.capImgOverlay, S.els.capBgOverlay, S.els.pickOverlay].forEach(function (ov) {
      if (ov) ov.style.display = 'none';
    });
  }

  // ---------- 验证结果提示（全部验证方式共用） ----------

  var toastTimer = null;
  var shakeTimer = null;

  /** 验证结果反馈：卡片顶部浮层提示 + 失败时卡片轻晃 */
  function showToast(ok, msg) {
    var el = S.els.toast;
    if (el) {
      el.textContent = (ok ? '✓ ' : '✕ ') + msg;
      el.className = 'verify-toast is-on ' + (ok ? 'is-ok' : 'is-err');
      clearTimeout(toastTimer);
      toastTimer = setTimeout(function () {
        el.className = 'verify-toast ' + (ok ? 'is-ok' : 'is-err');
      }, 2400);
    }
    if (!ok && S.els.card) {
      S.els.card.classList.remove('is-err-shake');
      void S.els.card.offsetWidth; // 重启动画
      S.els.card.classList.add('is-err-shake');
      clearTimeout(shakeTimer);
      shakeTimer = setTimeout(function () { S.els.card.classList.remove('is-err-shake'); }, 500);
    }
  }

  function clearToast() {
    if (S.els.toast) S.els.toast.className = 'verify-toast';
  }

  /**
   * 完成时机预检：用户完成某个验证方式后立即给出结果反馈。
   * 走 m=captcha&a=verify（服务端 $consume=false，不消耗 token）；
   * 失败计入尝试次数（上限 5 次，防穷举），且失败后自动换新题，避免用户继续无效尝试。
   */
  var checkBusy = false;
  function preCheck() {
    if (!S.token || checkBusy || S.mode === 'passive') return;
    checkBusy = true;
    apiVerify({ token: S.token, answer: answer() }).then(function (res) {
      checkBusy = false;
      if (res && res.code === 0) {
        showToast(true, '验证成功');
      } else {
        showToast(false, (res && res.message) || '验证失败，请重试');
        setTimeout(function () { reset(); }, 700);
      }
    }).catch(function () {
      checkBusy = false; // 网络异常不打扰用户，提交时服务端仍会校验
    });
  }

  // ---------- 滑块 ----------

  /**
   * 滑块 UI 更新：只写 CSS 变量 --cap-p（进度 0~100）。
   * 进度条宽度与 thumb 位置均由 CSS 的 --cap-x 统一计算：
   *   x = thumb/2 + p * (轨道宽 - thumb) / 100
   * 因此：
   *   1) thumb 始终完整落在轨道内（两端不会被 overflow 裁切，也不会越界）；
   *   2) 断点改变 thumb 尺寸（36px→44px）时无需 JS 参与重算；
   *   3) 容器宽度变化（响应式 / 旋转屏 / 侧栏折叠）时百分比自动跟随。
   */
  function updateSliderUI(min, max, val) {
    var pct = max > min ? ((val - min) / (max - min) * 100) : 0;
    if (pct < 0) pct = 0; if (pct > 100) pct = 100;
    setProgress(pct);
  }

  // 是否支持 CSS 自定义属性（不支持时才退化写内联样式，避免行内样式覆盖 CSS 的 --cap-x 计算）
  var SUPPORTS_VAR = !!(global.CSS && CSS.supports && CSS.supports('--cap-p', '0'));

  function setProgress(pct) {
    pct = Math.round(pct * 10) / 10;
    if (S.els.capSlider) S.els.capSlider.style.setProperty('--cap-p', String(pct));
    if (SUPPORTS_VAR) return; // 位置/宽度完全交给 CSS 计算，保证 thumb 与 progress 严丝合缝
    // 兜底：旧浏览器退化为「百分比 + 自身居中偏移」
    if (S.els.capFill) S.els.capFill.style.width = pct + '%';
    if (S.els.capThumb) S.els.capThumb.style.left = pct + '%';
  }

  // 拖拽中关闭过渡动画，保证跟手；松开后恢复过渡用于回弹/复位
  function markDragging(on) {
    if (S.els.capSlider) S.els.capSlider.classList.toggle('is-dragging', !!on);
  }
  function markFocus(on) {
    if (S.els.capSlider) S.els.capSlider.classList.toggle('is-focus', !!on);
  }

  // 滑块值（自然像素坐标 0..(w - 拼图边长)）按百分比映射到拼图块在背景图上的 left
  function positionPiece() {
    if (!S.els.capPiece) return;
    var w = S.natW || 300;
    var v = S.els.capRange ? (parseInt(S.els.capRange.value, 10) || 0) : 0;
    S.els.capPiece.style.left = (v / w * 100) + '%';
  }

  var ICON = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:4px"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><circle cx="12" cy="12" r="3"/></svg>';

  function setHintDefault() {
    if (!S.els.capHint) return;
    S.els.capHint.innerHTML = ICON + '拖动滑块，将拼图对齐缺口位置';
    S.els.capHint.style.color = '';
  }
  function setHintMove(pct) {
    if (!S.els.capHint) return;
    S.els.capHint.innerHTML = ICON + '已拖动 ' + Math.round(pct) + '%，松开可微调';
    S.els.capHint.style.color = '#2563eb';
  }

  // ---------- 点选（click / icon） ----------

  function buildPickGrid(d) {
    var grid = S.els.pickGrid;
    if (!grid) return;
    grid.innerHTML = '';
    grid.style.gridTemplateColumns = 'repeat(' + (d.cols || 4) + ',1fr)';
    grid.style.gridTemplateRows = 'repeat(' + (d.rows || 2) + ',1fr)';
    for (var i = 0; i < (d.tiles || 0); i++) {
      (function (idx) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'verify-pick-tile';
        b.setAttribute('data-i', idx);
        b.setAttribute('aria-label', '点选第 ' + (idx + 1) + ' 个');
        b.addEventListener('click', function (ev) {
          ev.preventDefault();
          onPickTile(idx, b);
        });
        grid.appendChild(b);
      })(i);
    }
    S.picked = [];
    refreshPickUI();
  }

  function onPickTile(idx, btn) {
    var at = S.picked.indexOf(idx);
    if (S.pickOrdered) {
      // 文字点选：必须按提示顺序；仅允许撤销最后一个
      if (at > -1) {
        if (at === S.picked.length - 1) { S.picked.pop(); }
        else { flashTile(btn, 'err'); return; }
      } else {
        if (S.picked.length >= S.pickLimit) { flashTile(btn, 'err'); return; }
        S.picked.push(idx);
      }
    } else {
      // 图标选择：不计序，可任意点选/取消
      if (at > -1) { S.picked.splice(at, 1); }
      else {
        if (S.picked.length >= S.pickLimit) { flashTile(btn, 'err'); return; }
        S.picked.push(idx);
      }
    }
    refreshPickUI();
    // 点选完成即预检并反馈结果（click 按序 / icon 不计序，集满即校验）
    if (S.picked.length === S.pickLimit && S.pickLimit > 0) preCheck();
  }

  function refreshPickUI() {
    var grid = S.els.pickGrid;
    if (!grid) return;
    var tiles = grid.querySelectorAll('.verify-pick-tile');
    for (var i = 0; i < tiles.length; i++) {
      var at = S.picked.indexOf(i);
      tiles[i].classList.toggle('is-picked', at > -1);
      var badge = tiles[i].querySelector('.verify-pick-badge');
      if (at > -1) {
        if (!badge) {
          badge = document.createElement('span');
          badge.className = 'verify-pick-badge';
          tiles[i].appendChild(badge);
        }
        badge.textContent = S.pickOrdered ? (at + 1) : '✓';
      } else if (badge) {
        badge.parentNode.removeChild(badge);
      }
    }
    if (S.els.footHint) {
      var left = S.pickLimit - S.picked.length;
      S.els.footHint.textContent = left > 0
        ? ('还需点选 ' + left + ' 个，点错可取消后重选')
        : '已完成点选，可以提交';
    }
  }

  function flashTile(btn, type) {
    btn.classList.remove('shake-err', 'shake-ok');
    void btn.offsetWidth; // 重启动画
    btn.classList.add(type === 'err' ? 'shake-err' : 'shake-ok');
    setTimeout(function () { btn.classList.remove('shake-err', 'shake-ok'); }, 500);
  }

  // ---------- 无感验证 ----------

  function startPassive() {
    stopPassive();
    S.blocked = false;
    var cfg = S.passiveCfg || {};
    var collectMs = cfg.collect_ms || 1600;
    var maxPoints = cfg.max_points || 120;
    // 采样节流：保证 1.6s 窗口内点数不超过上限，又不会过稀
    var minInterval = Math.max(8, Math.floor(collectMs / maxPoints));
    var p = {
      t0: Date.now(),
      traj: [],          // 原始轨迹：[相对毫秒, x, y]
      env: collectEnv(), // 环境指纹（服务端参与评分）
      last: 0,
      _x: null, _y: null,
      touch: ('ontouchstart' in global) || (navigator.maxTouchPoints > 0)
    };
    S.passive = p;
    function onMove(ev) {
      var pt = ev.touches && ev.touches[0] ? ev.touches[0] : ev;
      if (!pt || typeof pt.clientX !== 'number') return;
      var now = Date.now();
      if (p.traj.length && (now - p.last) < minInterval) return; // 节流
      p.last = now;
      p.traj.push([now - p.t0, Math.round(pt.clientX), Math.round(pt.clientY)]);
    }
    p._onMove = onMove;
    document.addEventListener('mousemove', onMove, { passive: true });
    document.addEventListener('touchmove', onMove, { passive: true });

    if (S.els.passiveText) {
      S.els.passiveText.textContent = '正在检测当前环境安全性，请稍候…';
      S.els.passiveText.className = 'verify-passive__text';
    }
    if (S.els.passiveSpin) S.els.passiveSpin.style.display = '';
    if (S.els.footHint) S.els.footHint.textContent = '无感验证中，请勿关闭页面';

    // 采集窗口：collect_ms 后提交原始轨迹由服务端重算评分（防伪造聚合值）
    S.passiveTimer = setTimeout(submitPassive, collectMs);
  }

  // 采集环境指纹（设备能力 / 时区 / 语言 / 像素比），作为服务端风险信号之一
  function collectEnv() {
    var env = {
      touch: ('ontouchstart' in global) || (navigator.maxTouchPoints > 0) ? 1 : 0,
      lang: navigator.language || '',
      tz: (typeof Date !== 'undefined') ? (-new Date().getTimezoneOffset()) : '',
      pxr: window.devicePixelRatio || '',
      dpr: window.devicePixelRatio || ''
    };
    if (navigator.hardwareConcurrency) env.cores = navigator.hardwareConcurrency;
    if (navigator.deviceMemory) env.mem = navigator.deviceMemory;
    return env;
  }

  function stopPassive() {
    if (S.passiveTimer) { clearTimeout(S.passiveTimer); S.passiveTimer = null; }
    if (S.passive && S.passive._onMove) {
      document.removeEventListener('mousemove', S.passive._onMove);
      document.removeEventListener('touchmove', S.passive._onMove);
    }
  }

  function passiveAnswer() {
    var p = S.passive;
    if (!p) return '';
    return JSON.stringify({
      traj: p.traj,
      env: p.env
    });
  }

  // 无感验证预检：优先走直连接口（静默），否则退回通用 req
  function apiVerify(params) {
    if (S.api) {
      var form = new FormData();
      for (var k in params) form.append(k, params[k]);
      return fetch(S.api + '?m=captcha&a=verify', {
        method: 'POST', body: form, credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      }).then(function (r) { return r.json(); });
    }
    return S.req('captcha', 'verify', params, { okMsg: false });
  }

  function submitPassive() {
    var ans = passiveAnswer();
    apiVerify({ token: S.token, answer: ans }).then(function (res) {
      if (res && res.code === 0) {
        if (S.els.passiveSpin) S.els.passiveSpin.style.display = 'none';
        if (S.els.passiveText) {
          S.els.passiveText.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-3px;margin-right:4px"><polyline points="20 6 9 17 4 12"/></svg>环境安全，验证通过';
          S.els.passiveText.className = 'verify-passive__text is-ok';
        }
        if (S.els.footHint) S.els.footHint.textContent = '已通过无感验证';
        showToast(true, '无感验证通过');
        S.passive.passed = true;
      } else {
        var m = res && res.message ? res.message : '';
        if (m === '__passive_block__') {
          // 风险评分触发「直接拦截」：阻断而非回退，避免恶意流量进入交互式验证
          showToast(false, '安全验证未通过，访问已被拦截，请稍后重试');
          if (S.els.footHint) S.els.footHint.textContent = '访问被拦截，请刷新页面后重试';
          S.blocked = true;
          stopPassive();
        } else {
          // 中风险：评分未通过，自动回退到交互式验证码（管理员配置的 captcha_fallback）
          showToast(false, '安全验证未通过，已切换验证方式');
          load(S.fallback);
        }
      }
    }).catch(function () {
      showToast(false, '安全验证未通过，请重试');
      load(S.fallback);
    });
  }

  // ---------- 加载验证码 ----------

  function load(forceStyle) {
    if (!S.show || !S.active()) return;
    clearToast();
    var style = forceStyle || S.style;
    S.curStyle = style;
    if (style === 'passive') {
      setMode('passive');
      showLoading();
      S.req('captcha', 'issue', { style: 'passive' }, { okMsg: false }).then(function (res) {
        hideLoading();
        if (!res || res.code !== 0) { load(S.fallback); return; }
        S.token = (res.data && res.data.token) || '';
        S.passiveCfg = (res.data && res.data) || {};
        startPassive();
      }).catch(function () { load(S.fallback); });
      return;
    }
    setMode(style);
    showLoading();
    S.req('captcha', 'issue', { style: style }, { okMsg: false }).then(function (res) {
      hideLoading();
      if (!res || res.code !== 0) return;
      var d = res.data || {};
      S.token = d.token || '';
      var st = d.style || style;
      setMode(st);

      if (d.image) {
        // digits / mixed
        S.els.capImg.src = d.image;
        S.els.capImg.onload = function () { hideLoading(); };
        if (S.els.answer) {
          S.els.answer.value = '';
          S.els.answer.placeholder = '请输入图中 4 位字符';
        }
        if (S.els.footHint) S.els.footHint.textContent = '看不清？换一张试试';
      } else if (d.bg) {
        // slide
        S.natW = d.width || 300;
        S.natH = d.height || 150;
        var s = d.size || 50;
        // 缺口纵向位置；前端据此把拼图块定位到与缺口同一水平带，便于横向拖入
        var hy = (d.hole_y != null) ? parseInt(d.hole_y, 10) : Math.round(S.natH / 2 - s / 2);

        S.els.capBg.src = d.bg;
        S.els.capBg.onload = function () { hideLoading(); };

        if (S.els.capPiece) {
          S.els.capPiece.src = d.piece || '';
          S.els.capPiece.style.width = (s / S.natW * 100) + '%';
          S.els.capPiece.style.height = (s / S.natH * 100) + '%';
          S.els.capPiece.style.top = (hy / S.natH * 100) + '%';
          S.els.capPiece.style.left = '0%';
        }

        // 可拖动上限 = 背景宽 - 拼图边长：拖到底时拼图块右边缘正好贴住背景右边缘，
        // 而缺口 x ∈ [s+10, w-s-10] 必在范围内，既够得着又不会把拼图拖出画布
        var travel = Math.max(1, S.natW - s);
        if (S.els.capRange) {
          S.els.capRange.min = 0;
          S.els.capRange.max = travel;
          S.els.capRange.step = 1;
          S.els.capRange.value = 0;
        }
        updateSliderUI(0, travel, 0);
        positionPiece();
        setHintDefault();
        if (S.els.footHint) S.els.footHint.textContent = '按住滑块拖动，将拼图对齐缺口';
      } else if (d.svg) {
        // click / icon
        S.pickOrdered = (st === 'click');
        S.pickLimit = st === 'click' ? 4 : (d.count || 3);
        if (S.els.prompt) S.els.prompt.textContent = d.prompt || '';
        if (S.els.pickImg) {
          S.els.pickImg.onload = function () { hideLoading(); };
          S.els.pickImg.src = d.svg;
        }
        buildPickGrid(d);
      }
    }).catch(function (err) {
      hideLoading();
      console.error('验证码加载失败:', err);
    });
  }

  // ---------- 事件绑定 ----------

  function bind() {
    if (S.els.capRange) {
      var range = S.els.capRange;
      function maxOf() { return parseInt(range.max, 10) || 250; }
      function syncFromRange(showPct) {
        var max = maxOf();
        var val = parseInt(range.value, 10) || 0;
        var pct = max > 0 ? Math.min(100, Math.max(0, val / max * 100)) : 0;
        updateSliderUI(0, max, val);
        positionPiece();
        if (showPct) setHintMove(pct);
      }
      range.addEventListener('input', function () { syncFromRange(true); });
      // 松开滑块（含键盘调节）即预检并反馈结果
      range.addEventListener('change', function () { markDragging(false); setHintDefault(); preCheck(); });
      // 指针按下/抬起：拖拽期间关闭过渡（跟手），抬起后恢复
      range.addEventListener('pointerdown', function () { markDragging(true); });
      document.addEventListener('pointerup', function () { markDragging(false); });
      document.addEventListener('pointercancel', function () { markDragging(false); });
      range.addEventListener('focus', function () { markFocus(true); });
      range.addEventListener('blur', function () { markFocus(false); });
      // 键盘可达性：range 原生支持左右方向键 / Home / End，这里同步 UI 与提示
      range.addEventListener('keyup', function () { syncFromRange(true); });
      range.addEventListener('keydown', function (ev) {
        if (ev.key === 'Home' || ev.key === 'End' || ev.key.indexOf('Arrow') === 0) {
          setTimeout(function () { syncFromRange(true); }, 0);
        }
      });
    }
    if (S.els.capImg) {
      S.els.capImg.addEventListener('click', function () { load(); });
    }
    if (S.els.refreshBtn) {
      S.els.refreshBtn.addEventListener('click', function () {
        if (S.els.answer) S.els.answer.value = '';
        stopPassive();
        showLoading();
        load(S.curStyle); // 保持当前样式（无感验证回退后不会反复重试无感）
      });
    }
    if (S.els.answer) {
      // 回车提交由表单统一处理；这里仅做大写规范化（mixed 不区分大小写）
      var textCheckTimer = null;
      S.els.answer.addEventListener('input', function () {
        if (this.value && /^[a-zA-Z]+$/.test(this.value)) this.value = this.value.toUpperCase();
        // 输满 4 位后预检并反馈结果（防抖 400ms，避免连续击键重复请求）
        clearTimeout(textCheckTimer);
        if ((this.value || '').trim().length >= 4) {
          textCheckTimer = setTimeout(preCheck, 400);
        }
      });
    }
  }

  // ---------- 对外取值 ----------

  function answer() {
    if (!S.show || !S.active()) return '';
    if (S.mode === 'passive') {
      // 无感验证：提交行为数据（评分未通过时前端已回退，不会走到这里）
      return S.passive && S.passive.passed ? passiveAnswer() : '';
    }
    if (S.mode === 'pick') {
      if (S.pickOrdered) return S.picked.join(',');
      var sorted = S.picked.slice().sort(function (a, b) { return a - b; });
      return sorted.join(',');
    }
    if (S.mode === 'slide') {
      return S.els.capRange ? S.els.capRange.value : '';
    }
    return S.els.answer ? (S.els.answer.value || '').trim() : '';
  }

  function ready() {
    if (!S.show || !S.active()) return true;
    if (!S.token) return false;
    if (S.mode === 'passive') return !!(S.passive && S.passive.passed);
    if (S.mode === 'pick') return S.picked.length === S.pickLimit && S.pickLimit > 0;
    if (S.mode === 'slide') return true; // range 总有值，提交时校验
    return !!(S.els.answer && (S.els.answer.value || '').trim());
  }

  function reset() { stopPassive(); S.token = ''; clearToast(); load(); }

  function init(opts) {
    opts = opts || {};
    S.req = opts.req;
    S.api = opts.api || '';
    S.show = !!opts.show;
    S.style = opts.style || 'slide';
    S.fallback = opts.fallback || 'slide';
    if (typeof opts.active === 'function') S.active = opts.active;
    // 已持有无感信任凭证（跨页无缝放行）：后端在 partials/captcha_card.php 命中信任时置位。
    // 此时卡片未渲染、无需行为采集，直接视为已验证，避免重复验证并立即放行。
    if (global.__UIC_CAP_TRUSTED__) { S.show = false; }
    cache();
    if (!S.show) return;
    bind();
    if (document.readyState !== 'loading') load();
    else document.addEventListener('DOMContentLoaded', function () { load(); });
  }

  global.UicCaptcha = {
    init: init,
    get token() { return S.token; },
    get mode() { return S.mode; },
    answer: answer,
    ready: ready,
    reset: reset
  };
})(window);
