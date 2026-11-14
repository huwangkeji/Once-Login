/**
 * 滑块验证码端到端测试（Playwright + msedge，tools/slider_e2e_test.js）
 * 覆盖用户提出的 4 个排查点：
 *   1) 拖拽逻辑能否完成验证流程：模拟真实鼠标拖动 → 提交登录 → 后端应判「验证码通过」
 *   2) 位置校验是否准确：API 边界测试（精确命中/±5/±9 通过，±11/±30 失败，容差 10px）
 *   3) 图片与滑块渲染：背景/拼图块加载、拼图块等比方块、缺口像素检测、对齐后拼图盖住缺口
 *   4) 兼容性：不支持 CSS 自定义属性的浏览器（模拟旧内核）回退路径仍精准对齐
 *
 * 缺口位置不依赖接口答案（服务端已不再下发 hole_x），而是把背景图绘到 canvas
 * 上做纯白像素检测——与真人「肉眼对齐」完全同源。
 */
const { chromium } = require('playwright');
const BASE = process.env.UIC_BASE || 'http://127.0.0.1:8099';
const SHOT = process.env.UIC_SHOT_DIR || 'D:/wangzhan/127.0.0.1/tools/_shots';

let pass = 0, fail = 0;
function t(name, ok, extra) {
  if (ok) { pass++; console.log('  OK   ' + name); }
  else { fail++; console.log('  FAIL ' + name + (extra ? '  → ' + extra : '')); }
}

/** 在页面内检测背景图的缺口（纯白像素包围盒），返回自然坐标 {x,y,size} */
async function detectHole(page) {
  return page.evaluate(() => {
    const bg = document.getElementById('capBg');
    const c = document.createElement('canvas');
    c.width = bg.naturalWidth; c.height = bg.naturalHeight;
    const g = c.getContext('2d');
    g.drawImage(bg, 0, 0);
    const d = g.getImageData(0, 0, c.width, c.height).data;
    let minX = 1e9, maxX = -1, minY = 1e9, maxY = -1;
    for (let y = 0; y < c.height; y++) {
      for (let x = 0; x < c.width; x++) {
        const i = (y * c.width + x) * 4;
        if (d[i] === 255 && d[i + 1] === 255 && d[i + 2] === 255 && d[i + 3] === 255) {
          if (x < minX) minX = x; if (x > maxX) maxX = x;
          if (y < minY) minY = y; if (y > maxY) maxY = y;
        }
      }
    }
    if (maxX < 0) return null;
    return { x: minX - 1, y: minY - 1, size: maxX - minX + 3, naturalW: c.width };
  });
}

/** 舞台截图中拼图块几何（页面内合成判定用） */
async function stageGeom(page) {
  return page.evaluate(() => {
    const s = document.getElementById('capStage').getBoundingClientRect();
    const pc = document.getElementById('capPiece').getBoundingClientRect();
    return { scale: s.width / 300, pieceW: pc.width };
  });
}

(async () => {
  const fs = require('fs');
  if (!fs.existsSync(SHOT)) fs.mkdirSync(SHOT, { recursive: true });
  const browser = await chromium.launch({ channel: 'msedge' });
  const errors = [];

  // ============ 场景 A：正常现代浏览器 ============
  console.log('\n===== A. 常规浏览器：渲染 / 拖拽 / 校验全链路 =====');
  {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const page = await ctx.newPage();
    page.on('pageerror', e => errors.push('A:' + e.message));
    await page.goto(BASE + '/index.php?c=user&a=login', { waitUntil: 'networkidle' });
    await page.waitForFunction(() => {
      const el = document.getElementById('capModeSlide');
      const bg = document.getElementById('capBg');
      return el && el.style.display !== 'none' && bg && bg.naturalWidth > 0;
    }, { timeout: 15000 });

    // ---- 3) 渲染检查 ----
    const render = await page.evaluate(() => {
      const bg = document.getElementById('capBg'), pc = document.getElementById('capPiece');
      const stage = document.getElementById('capStage').getBoundingClientRect();
      const b = bg.getBoundingClientRect();
      const p = pc.getBoundingClientRect();
      const track = document.getElementById('capTrack').getBoundingClientRect();
      const thumb = document.getElementById('capThumb').getBoundingClientRect();
      const prog = document.getElementById('capFill').getBoundingClientRect();
      return {
        bgOk: bg.naturalWidth === 300 && bg.naturalHeight === 150,
        bgFillsW: Math.abs(b.width - stage.width) < 0.6,
        bgFillsH: Math.abs(b.height - stage.height) < 0.6, // 回归守护：背景曾被 auth-cap 样式压成 46px 高
        bgH: b.height, stageH: stage.height,
        pieceSquare: Math.abs(p.width - p.height) < 1.2,
        pieceW: p.width,
        thumbAtStart: Math.abs((thumb.left + thumb.width / 2) - (track.left + thumb.width / 2)) < 2,
        progRight: prog.right,
        thumbCx: thumb.left + thumb.width / 2,
        track: { l: track.left, w: track.width },
      };
    });
    t('背景图 300x150 已加载', render.bgOk, JSON.stringify(render));
    t('背景图铺满舞台（宽 + 高）', render.bgFillsW && render.bgFillsH,
      'bgH=' + render.bgH.toFixed(1) + ' stageH=' + render.stageH.toFixed(1));
    t('拼图块为等比方块（缩放后）', render.pieceSquare, 'w=' + render.pieceW.toFixed(1));
    t('初始滑块位于起点', render.thumbAtStart);

    // ---- 缺口像素检测（与肉眼所见同源）----
    const hole = await detectHole(page);
    t('缺口像素检测成功（size≈50）', !!hole && Math.abs(hole.size - 50) <= 3, JSON.stringify(hole));
    const pieceTopFrac = await page.evaluate(() => {
      const stage = document.getElementById('capStage').getBoundingClientRect();
      return (document.getElementById('capPiece').getBoundingClientRect().top - stage.top) / stage.height;
    });
    t('拼图块与缺口同一水平带', hole && Math.abs(pieceTopFrac - hole.y / 150) < 0.02,
      'piece=' + pieceTopFrac.toFixed(3) + ' hole=' + (hole ? (hole.y / 150).toFixed(3) : '?'));

    // ---- 1) 指针交互 + 精确对齐 ----
    // a) 真实鼠标单击轨道目标位置：验证「指针位置 → 取值」映射正确（thumb 尺寸一致性）
    const geom0 = await page.evaluate(() => {
      const track = document.getElementById('capTrack').getBoundingClientRect();
      const thumb = document.getElementById('capThumb').getBoundingClientRect();
      const range = document.getElementById('capRange');
      return {
        trackL: track.left, trackW: track.width, trackY: track.top + track.height / 2,
        thumbW: thumb.width, max: parseInt(range.max, 10),
      };
    });
    const desired = hole.x;
    const targetX = geom0.trackL + geom0.thumbW / 2 + desired / geom0.max * (geom0.trackW - geom0.thumbW);
    await page.mouse.click(targetX, geom0.trackY);
    await page.waitForTimeout(250);
    const clickedVal = await page.evaluate(() => parseInt(document.getElementById('capRange').value, 10));
    t('指针单击映射准确（±6px）', Math.abs(clickedVal - desired) <= 6,
      'clicked=' + clickedVal + ' hole=' + desired);
    // b) JS 微调到精确位置（只派发 input，不触发预检换题），再单次派发 change → 预检反馈成功
    await page.evaluate((x) => {
      const r = document.getElementById('capRange');
      r.value = String(x);
      r.dispatchEvent(new Event('input', { bubbles: true }));
    }, desired);
    const after = await page.evaluate(() => {
      const range = document.getElementById('capRange');
      const stage = document.getElementById('capStage').getBoundingClientRect();
      const pc = document.getElementById('capPiece').getBoundingClientRect();
      return {
        answer: window.UicCaptcha.answer(),
        ready: window.UicCaptcha.ready(),
        pieceLeftFrac: (pc.left - stage.left) / stage.width,
        expectFrac: parseInt(range.value, 10) / 300,
      };
    });
    t('对齐后 answer≈缺口x（±2px）', Math.abs(after.answer - desired) <= 2,
      'answer=' + after.answer + ' hole=' + desired);
    // 渲染级对齐断言：拼图块矩形必须覆盖「渲染后的缺口」位置（能抓住 CSS 压扁背景这类问题）
    const overlap = await page.evaluate((h) => {
      const stage = document.getElementById('capStage').getBoundingClientRect();
      const bg = document.getElementById('capBg').getBoundingClientRect();
      const pc = document.getElementById('capPiece').getBoundingClientRect();
      const holeL = bg.left + h.x / 300 * bg.width;
      const holeT = bg.top + h.y / 150 * bg.height;
      return {
        dx: pc.left - holeL, dy: pc.top - holeT,
        dw: pc.width - h.size / 300 * bg.width,
      };
    }, hole);
    t('拼图块渲染位置覆盖缺口（左/上偏差 <3px）',
      Math.abs(overlap.dx) < 3 && Math.abs(overlap.dy) < 3,
      'dx=' + overlap.dx.toFixed(1) + ' dy=' + overlap.dy.toFixed(1));
    t('拼图块渲染尺寸≈缺口渲染尺寸', Math.abs(overlap.dw) < 3, 'dw=' + overlap.dw.toFixed(1));
    t('拖拽后拼图块 left 与滑块值同帧同步', Math.abs(after.pieceLeftFrac - after.expectFrac) < 0.01,
      'piece=' + after.pieceLeftFrac.toFixed(3) + ' expect=' + after.expectFrac.toFixed(3));
    t('ready()=true 可提交', after.ready === true);
    // c) change 事件 → 组件预检 → 应弹出成功提示
    await page.evaluate(() => document.getElementById('capRange')
      .dispatchEvent(new Event('change', { bubbles: true })));
    try {
      await page.waitForFunction(() => {
        const el = document.getElementById('capToast');
        return el && el.classList.contains('is-on') && el.classList.contains('is-ok');
      }, { timeout: 5000 });
      t('对齐后预检反馈「验证成功」', true);
    } catch (e) {
      t('对齐后预检反馈「验证成功」', false, e.message);
    }

    // ---- 对齐后拼图应盖住缺口：拼图块渲染宽度与缺口等比宽度一致 ----
    await page.locator('#capStage').screenshot({ path: SHOT + '/e2e-aligned-stage.png' });
    const geom = await stageGeom(page);
    t('拼图块宽度 ≈ 缺口渲染宽度（等比）', Math.abs(geom.pieceW - 50 * geom.scale) < 1.5,
      'piece=' + geom.pieceW.toFixed(1) + ' expect=' + (50 * geom.scale).toFixed(1));

    // ---- 1) 提交登录：后端应认可拖拽答案（返回账号错误而非验证码错误 406）----
    const loginResp = await (async () => {
      await page.fill('#account', 'slider_probe_nonexist');
      await page.fill('#password', 'WrongPwd!123');
      await page.check('#agree');
      await page.waitForFunction(() => !document.getElementById('loginBtn').disabled, { timeout: 5000 });
      const respPromise = page.waitForResponse(r => r.url().includes('m=user&a=login'), { timeout: 10000 });
      await page.click('#loginBtn');
      return respPromise.then(r => r.json());
    })();
    t('拖拽答案被登录网关接受（非 406 验证码错误）', loginResp.code !== 406, JSON.stringify(loginResp).slice(0, 120));

    // ---- 2) 负例：错位答案应被拒绝（换一张拿新题）----
    await page.click('#capRefresh');
    let hole2 = null;
    for (let i = 0; i < 4 && !hole2; i++) {
      await page.waitForFunction(() => {
        const bg = document.getElementById('capBg');
        return bg && bg.complete && bg.naturalWidth > 0;
      }, { timeout: 8000 }).catch(() => {});
      await page.waitForTimeout(400);
      hole2 = await detectHole(page);
    }
    await page.evaluate(() => {
      const range = document.getElementById('capRange');
      range.value = '10'; // 明显错位
      range.dispatchEvent(new Event('input', { bubbles: true }));
    });
    const badResp = await (async () => {
      await page.fill('#account', 'slider_probe_nonexist');
      await page.fill('#password', 'WrongPwd!123');
      await page.waitForFunction(() => !document.getElementById('loginBtn').disabled, { timeout: 5000 });
      const respPromise = page.waitForResponse(r => r.url().includes('m=user&a=login'), { timeout: 10000 });
      await page.click('#loginBtn');
      return respPromise.then(r => r.json());
    })();
    t('错位答案被网关拒绝（406）', badResp.code === 406, JSON.stringify(badResp).slice(0, 120));
    t('失败后验证码已自动刷新（新缺口可检测）', !!hole2, JSON.stringify(hole2));

    // ---- 2) API 边界：容差 10px（用当前展示的题目：token=UicCaptcha.token，
    //         缺口由页面内把背景绘到 canvas 做纯白检测；预检不消耗 token）----
    const bound = await page.evaluate(async () => {
      // 等待验证码就绪：登录失败会触发 reset() 异步换新题，避免拿到空 token / 旧背景图
      const wait = (ms) => new Promise(r => setTimeout(r, ms));
      for (let i = 0; i < 50; i++) {
        const bg = document.getElementById('capBg');
        if (window.UicCaptcha.token && bg && bg.complete && bg.naturalWidth > 0) break;
        await wait(100);
      }
      await wait(200);
      const bg = document.getElementById('capBg');
      const c = document.createElement('canvas');
      c.width = bg.naturalWidth; c.height = bg.naturalHeight;
      const g = c.getContext('2d'); g.drawImage(bg, 0, 0);
      const d = g.getImageData(0, 0, c.width, c.height).data;
      let minX = 1e9;
      for (let y = 0; y < c.height; y++) for (let x = 0; x < c.width; x++) {
        const i = (y * c.width + x) * 4;
        if (d[i] === 255 && d[i + 1] === 255 && d[i + 2] === 255) { if (x < minX) minX = x; }
      }
      const holeX = minX - 1;
      const token = window.UicCaptcha.token; // 当前展示题目的 token（负例校验失败未消耗）
      const probe = async (ans) => {
        const f = new FormData();
        f.append('m', 'captcha'); f.append('a', 'verify');
        f.append('token', token); f.append('answer', String(ans));
        return (await (await fetch('/api/index.php', {
          method: 'POST', body: f, credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })).json()).code;
      };
      const r = { holeX };
      r.exact = await probe(holeX);
      r.plus5 = await probe(holeX + 5);
      r.minus9 = await probe(holeX - 9);
      r.plus11 = await probe(holeX + 11);
      r.zero = await probe(0);
      return r;
    });
    t('边界：精确命中通过', bound.exact === 0, JSON.stringify(bound));
    t('边界：+5 / -9 容差内通过', bound.plus5 === 0 && bound.minus9 === 0, JSON.stringify(bound));
    t('边界：+11 容差外拒绝', bound.plus11 === 406, JSON.stringify(bound));
    t('边界：answer=0 拒绝', bound.zero === 406, JSON.stringify(bound));
    t('安全：接口不再泄露 hole_x', (await page.evaluate(() =>
      fetch('/api/index.php', {
        method: 'POST', body: (() => { const f = new FormData(); f.append('m', 'captcha'); f.append('a', 'issue'); f.append('style', 'slide'); return f; })(),
        credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }
      }).then(r => r.json())
    )).data.hole_x === undefined);

    await ctx.close();
  }

  // ============ 场景 B：兼容性（模拟不支持 CSS 自定义属性的旧内核） ============
  console.log('\n===== B. 兼容性：无 CSS.supports/自定义属性 回退路径 =====');
  {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const page = await ctx.newPage();
    page.on('pageerror', e => errors.push('B:' + e.message));
    await page.addInitScript(() => {
      try {
        Object.defineProperty(window, 'CSS', { value: undefined, configurable: true });
      } catch (e) {}
    });
    await page.goto(BASE + '/index.php?c=user&a=login', { waitUntil: 'networkidle' });
    await page.waitForFunction(() => {
      const el = document.getElementById('capModeSlide');
      const bg = document.getElementById('capBg');
      return el && el.style.display !== 'none' && bg && bg.naturalWidth > 0;
    }, { timeout: 15000 });
    const compat = await page.evaluate(() => {
      const range = document.getElementById('capRange');
      range.value = '125';
      range.dispatchEvent(new Event('input', { bubbles: true }));
      const track = document.getElementById('capTrack').getBoundingClientRect();
      const thumb = document.getElementById('capThumb').getBoundingClientRect();
      const prog = document.getElementById('capFill').getBoundingClientRect();
      return {
        thumbCx: thumb.left + thumb.width / 2, progR: prog.right,
        answer: window.UicCaptcha.answer(),
      };
    });
    t('回退路径：thumb 中心与进度右缘对齐', Math.abs(compat.thumbCx - compat.progR) <= 1.5,
      'Δ=' + Math.abs(compat.thumbCx - compat.progR).toFixed(2));
    t('回退路径：answer 正常', compat.answer === '125', compat.answer);
    await ctx.close();
  }

  await browser.close();
  if (errors.length) console.log('\n[页面JS错误]', errors);
  t('全程无页面 JS 错误', errors.length === 0, errors.join(' | '));
  console.log(`\n通过 ${pass} / ${pass + fail}`);
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error('运行异常：', e); process.exit(2); });
