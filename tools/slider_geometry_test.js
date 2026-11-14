/**
 * 滑块验证码几何对齐测试（Playwright + msedge）
 * 校验点：
 *   1) 背景图铺满舞台（不因容器宽于 300px 而左对齐错位）
 *   2) thumb 中心 与 progress 右边缘严格同点（±1.5px）
 *   3) thumb 在 0% / 50% / 100% 时均完整落在轨道内（不被裁切、不越界）
 *   4) 拼图块宽度随舞台等比缩放，拖动时与 thumb 同步
 */
const { chromium } = require('playwright');

const BASE = process.env.UIC_BASE || 'http://127.0.0.1:8099';
const VIEWPORTS = [
  { name: 'desktop-1920', width: 1920, height: 1080 },
  { name: 'laptop-1280',  width: 1280, height: 800 },
  { name: 'tablet-768',   width: 768,  height: 1024 },
  { name: 'mobile-390',   width: 390,  height: 844 },
  { name: 'mobile-360',   width: 360,  height: 780 },
  { name: 'mobile-320',   width: 320,  height: 568 },
];
const SHOT = process.env.UIC_SHOT_DIR || 'D:/wangzhan/127.0.0.1/tools/_shots';

let pass = 0, fail = 0;
function t(name, ok, extra) {
  if (ok) { pass++; console.log('  OK   ' + name); }
  else { fail++; console.log('  FAIL ' + name + (extra ? '  → ' + extra : '')); }
}

(async () => {
  const browser = await chromium.launch({ channel: 'msedge' });
  const fs = require('fs');
  if (!fs.existsSync(SHOT)) fs.mkdirSync(SHOT, { recursive: true });

  for (const vp of VIEWPORTS) {
    console.log(`\n===== ${vp.name} (${vp.width}x${vp.height}) =====`);
    const ctx = await browser.newContext({ viewport: { width: vp.width, height: vp.height } });
    const page = await ctx.newPage();
    page.on('pageerror', e => console.log('  [页面JS错误] ' + e.message));
    await page.goto(BASE + '/index.php?c=user&a=login', { waitUntil: 'domcontentloaded' });
    try {
      await page.waitForFunction(() => {
        const el = document.getElementById('capModeSlide');
        const bg = document.getElementById('capBg');
        return el && el.style.display !== 'none' && bg && bg.naturalWidth > 0;
      }, { timeout: 15000 });
    } catch (e) {
      t('滑块模式已加载', false, e.message);
      await ctx.close();
      continue;
    }
    t('滑块模式已加载', true);

    // 1) 背景图铺满舞台
    const fill = await page.evaluate(() => {
      const stage = document.getElementById('capStage').getBoundingClientRect();
      const bg = document.getElementById('capBg').getBoundingClientRect();
      return { stageW: stage.width, stageH: stage.height, bgW: bg.width, bgH: bg.height, bgX: bg.x, stageX: stage.x };
    });
    t(`背景图宽度 = 舞台宽度 (${fill.bgW.toFixed(1)} vs ${fill.stageW.toFixed(1)})`,
      Math.abs(fill.bgW - fill.stageW) < 0.6, JSON.stringify(fill));
    t('背景图左边缘与舞台对齐', Math.abs(fill.bgX - fill.stageX) < 0.6);

    // 2/3) 多个进度点的几何
    const geo = await page.evaluate(async () => {
      const range  = document.getElementById('capRange');
      const track  = document.getElementById('capTrack').getBoundingClientRect();
      const thumb  = document.getElementById('capThumb');
      const prog   = document.getElementById('capFill');
      const piece  = document.getElementById('capPiece');
      const stage  = document.getElementById('capStage').getBoundingClientRect();
      const wait   = (ms) => new Promise(r => setTimeout(r, ms));
      const out = [];
      async function snap(pct) {
        range.value = String(Math.round((range.max - range.min) * pct / 100));
        range.dispatchEvent(new Event('input', { bubbles: true }));
        await wait(320); // 等过渡动画结束再量，否则量到的是动画中间态
        const th = thumb.getBoundingClientRect();
        const pr = prog.getBoundingClientRect();
        const pc = piece.getBoundingClientRect();
        out.push({
          pct,
          trackL: track.left, trackR: track.right, trackW: track.width,
          thumbL: th.left, thumbR: th.right, thumbCx: th.left + th.width / 2, thumbW: th.width,
          progR: pr.right,
          pieceW: pc.width, pieceL: pc.left, stageW: stage.width,
        });
      }
      for (const p of [0, 25, 50, 100]) { await snap(p); }
      return { out, max: range.max, natRatio: (50 / 300), cssVar: getComputedStyle(document.getElementById('capSlider')).getPropertyValue('--cap-p') };
    });
    t('--cap-p 已写入(100%时≈100)', Math.abs(parseFloat(geo.cssVar) - 100) < 0.6, '实际=' + geo.cssVar);
    // 拼图块应随拖动右移
    const p0 = geo.out[0], p100 = geo.out[geo.out.length - 1];
    t('拖动后拼图块右移', p100.pieceL > p0.pieceL + 50, `0%:${p0.pieceL.toFixed(1)} 100%:${p100.pieceL.toFixed(1)}`);

    for (const g of geo.out) {
      t(`${g.pct}% thumb中心 与 进度条右缘对齐 (Δ=${Math.abs(g.thumbCx - g.progR).toFixed(2)}px)`,
        Math.abs(g.thumbCx - g.progR) <= 1.5);
      t(`${g.pct}% thumb 完整落在轨道内 (L:${g.thumbL.toFixed(1)}≥${g.trackL.toFixed(1)} / R:${g.thumbR.toFixed(1)}≤${g.trackR.toFixed(1)})`,
        g.thumbL >= g.trackL - 0.6 && g.thumbR <= g.trackR + 0.6);
    }
    // 4) 拼图块等比缩放
    const last = geo.out[geo.out.length - 1];
    const expectW = last.stageW * geo.natRatio;
    t(`拼图块宽度等比 (${last.pieceW.toFixed(1)} ≈ ${expectW.toFixed(1)})`, Math.abs(last.pieceW - expectW) < 1.2);
    t(`滑块行程上限 = 背景宽-拼图边长 (max=${geo.max})`, Number(geo.max) === 250);

    // 截图（拖到 50%）
    await page.evaluate(() => {
      const range = document.getElementById('capRange');
      range.value = String(Math.round((range.max - range.min) * 0.5));
      range.dispatchEvent(new Event('input', { bubbles: true }));
    });
    await page.waitForTimeout(300);
    await page.screenshot({ path: `${SHOT}/slider-${vp.name}.png`, fullPage: false });
    await ctx.close();
  }

  await browser.close();
  console.log(`\n通过 ${pass} / ${pass + fail}`);
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error('运行异常：', e); process.exit(2); });
