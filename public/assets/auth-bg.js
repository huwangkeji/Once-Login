/**
 * 认证页动态星空背景（零依赖，public/assets/auth-bg.js）
 * ============================================================
 * 星星：逐星独立闪烁（相位/速度随机）+ 微量漂移，杜绝整屏同步闪动；
 * 流星：随机位置 / 角度(18°~38°) / 速度 / 间隔划出，带渐变拖尾，划过自然淡出，
 *       同屏最多 3 颗、间隔 3.5~10s 随机，无机械循环感；
 * 山脉：CSS 负责云雾横移 / 缓摆 / 山脊微光呼吸（见 app.css），本脚本负责鼠标视差——
 *       星空与山脉反向轻移，触屏设备自动跳过（不干扰滚动）；
 * 性能：单 canvas + 单 rAF；DPR 上限 1.5；星数按面积自适应(36~120)；
 *       页面隐藏自动暂停；prefers-reduced-motion 时只静态绘制一帧。
 */
(function () {
  'use strict';

  var canvas = document.getElementById('authSky');
  var skyWrap = document.getElementById('bgParallaxSky');
  var mountWrap = document.getElementById('bgParallaxMount');
  var reduce = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
  var finePointer = !!(window.matchMedia && window.matchMedia('(pointer: fine)').matches);

  // ---------------- 视差（不依赖 canvas，独立可用） ----------------
  if (skyWrap || mountWrap) {
    if (finePointer && !reduce) {
      var tx = 0, ty = 0, cx = 0, cy = 0, pid = 0;
      var step = function () {
        pid = 0;
        cx += (tx - cx) * 0.06;
        cy += (ty - cy) * 0.06;
        if (skyWrap) skyWrap.style.transform = 'translate3d(' + (cx * -8).toFixed(2) + 'px,' + (cy * -5).toFixed(2) + 'px,0)';
        if (mountWrap) mountWrap.style.transform = 'translate3d(' + (cx * 14).toFixed(2) + 'px,' + (cy * 5).toFixed(2) + 'px,0)';
        if (Math.abs(tx - cx) > 0.002 || Math.abs(ty - cy) > 0.002) pid = requestAnimationFrame(step);
      };
      document.addEventListener('mousemove', function (e) {
        tx = (e.clientX / window.innerWidth - 0.5) * 2;
        ty = (e.clientY / window.innerHeight - 0.5) * 2;
        if (!pid) pid = requestAnimationFrame(step);
      }, { passive: true });
    }
  }

  if (!canvas || !canvas.getContext) return;
  var ctx = canvas.getContext('2d');

  var TAU = Math.PI * 2;
  var dpr = Math.min(window.devicePixelRatio || 1, 1.5);
  var W = 0, H = 0;
  var stars = [];
  var meteors = [];
  var raf = 0, running = false, last = 0;
  var meteorWait = 2.5 + Math.random() * 3.5; // 首颗流星：2.5~6s 后出现
  var totalMeteors = 0;

  function rand(a, b) { return a + Math.random() * (b - a); }

  function resize() {
    W = canvas.clientWidth || window.innerWidth;
    H = canvas.clientHeight || window.innerHeight;
    canvas.width = Math.max(1, Math.round(W * dpr));
    canvas.height = Math.max(1, Math.round(H * dpr));
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    seed();
    if (reduce) drawStatic();
  }

  /** 星星按视口面积播种：亮度 / 半径 / 闪烁相位与速度 / 漂移速度全部独立随机 */
  function seed() {
    stars.length = 0;
    var n = Math.max(36, Math.min(120, Math.round(W * H / 16000)));
    for (var i = 0; i < n; i++) {
      var bright = Math.random() < 0.14;
      stars.push({
        x: Math.random() * W,
        y: Math.random() * H * 0.92,
        r: bright ? rand(1.1, 1.8) : rand(0.4, 1.1),
        a: bright ? rand(0.55, 0.95) : rand(0.25, 0.7), // 基础亮度
        ph: Math.random() * TAU,                        // 闪烁相位（错峰关键）
        sp: rand(0.25, 1.1),                            // 闪烁角速度 rad/s
        vx: rand(-0.12, 0.12),                          // 微漂移 px/s（数秒移动 1~2px）
        vy: rand(-0.06, 0.06),
        ray: bright && Math.random() < 0.6              // 少数亮星带十字光芒
      });
    }
  }

  /** 流星：方向 / 起点 / 速度 / 拖尾长度 / 存活时长均随机 */
  function spawnMeteor() {
    var ang = rand(18, 38) * Math.PI / 180;
    var dir = Math.random() < 0.82 ? 1 : -1; // 多数自一侧向另一侧，少数反向
    var sp = rand(260, 560);
    totalMeteors++;
    meteors.push({
      x: rand(0.05, 0.95) * W,
      y: rand(-30, H * 0.30),
      vx: Math.cos(ang) * sp * dir,
      vy: Math.sin(ang) * sp,
      len: rand(120, 260),
      life: 0,
      ttl: rand(0.9, 1.6)
    });
  }

  function drawMeteors(dt) {
    meteorWait -= dt;
    if (meteorWait <= 0 && meteors.length < 3) {
      spawnMeteor();
      meteorWait = rand(3.5, 10); // 下一颗：随机间隔，避免机械循环
    }
    for (var i = meteors.length - 1; i >= 0; i--) {
      var m = meteors[i];
      m.life += dt;
      m.x += m.vx * dt;
      m.y += m.vy * dt;
      var p = m.life / m.ttl;
      if (p >= 1 || m.x < -m.len - 80 || m.x > W + m.len + 80 || m.y > H + 80) {
        meteors.splice(i, 1);
        continue;
      }
      // 淡入(前12%) → 巡航 → 淡出(后30%)
      var fade = p < 0.12 ? p / 0.12 : (p > 0.7 ? (1 - p) / 0.3 : 1);
      var nl = Math.sqrt(m.vx * m.vx + m.vy * m.vy) || 1;
      var tx = m.x - m.vx / nl * m.len;
      var ty = m.y - m.vy / nl * m.len;
      var g = ctx.createLinearGradient(m.x, m.y, tx, ty);
      g.addColorStop(0, 'rgba(255,255,255,' + (0.85 * fade).toFixed(3) + ')');
      g.addColorStop(0.35, 'rgba(190,215,255,' + (0.35 * fade).toFixed(3) + ')');
      g.addColorStop(1, 'rgba(190,215,255,0)');
      ctx.globalAlpha = 1;
      ctx.strokeStyle = g;
      ctx.lineWidth = 1.6;
      ctx.lineCap = 'round';
      ctx.beginPath();
      ctx.moveTo(m.x, m.y);
      ctx.lineTo(tx, ty);
      ctx.stroke();
      // 头部亮点
      ctx.globalAlpha = fade;
      ctx.fillStyle = '#ffffff';
      ctx.beginPath();
      ctx.arc(m.x, m.y, 1.4, 0, TAU);
      ctx.fill();
    }
  }

  function drawStars(t, dt) {
    for (var i = 0; i < stars.length; i++) {
      var s = stars[i];
      // 逐星独立呼吸：相位 + 速度都不同，整屏不会同步闪动
      var tw = 0.55 + 0.45 * Math.sin(t * s.sp + s.ph);
      var a = s.a * (0.35 + 0.65 * tw);
      ctx.globalAlpha = a;
      ctx.fillStyle = '#ffffff';
      ctx.beginPath();
      ctx.arc(s.x, s.y, s.r, 0, TAU);
      ctx.fill();
      if (s.ray) {
        ctx.globalAlpha = a * 0.28;
        var L = s.r * 4;
        ctx.fillRect(s.x - L, s.y - 0.4, L * 2, 0.8);
        ctx.fillRect(s.x - 0.4, s.y - L, 0.8, L * 2);
      }
      // 微漂移（按 dt 积分，帧率无关），越界后从对侧回绕
      if (dt) {
        s.x += s.vx * dt;
        s.y += s.vy * dt;
        if (s.x < -4) s.x = W + 4; else if (s.x > W + 4) s.x = -4;
        if (s.y < -4) s.y = H + 4; else if (s.y > H + 4) s.y = -4;
      }
    }
  }

  function frame(now) {
    raf = 0;
    var t = now / 1000;
    var dt = last ? Math.min(0.05, t - last) : 0.016;
    last = t;
    ctx.clearRect(0, 0, W, H);
    drawStars(t, dt);
    drawMeteors(dt);
    ctx.globalAlpha = 1;
    if (running) raf = requestAnimationFrame(frame);
  }

  /** 静态绘制一帧（reduced-motion / 降级）：只有星点，无动画无流星 */
  function drawStatic() {
    ctx.clearRect(0, 0, W, H);
    drawStars(0, 0);
    ctx.globalAlpha = 1;
  }

  function start() {
    if (running || reduce) return;
    running = true;
    last = 0;
    if (!raf) raf = requestAnimationFrame(frame);
  }
  function stop() {
    running = false;
    if (raf) { cancelAnimationFrame(raf); raf = 0; }
  }

  var rsTimer = 0;
  window.addEventListener('resize', function () {
    clearTimeout(rsTimer);
    rsTimer = setTimeout(resize, 150);
  });
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) stop(); else start();
  });

  resize();
  if (reduce) { drawStatic(); return; }
  start();

  // 只读诊断钩子：供自动化测试 / 线上排查使用（不暴露任何可变更状态）
  window.__authBg = {
    stars:   function () { return stars.length; },
    meteors: function () { return meteors.length; },       // 当前在飞的流星数
    spawned: function () { return totalMeteors; },          // 累计生成的流星数
    running: function () { return running; }
  };
})();
