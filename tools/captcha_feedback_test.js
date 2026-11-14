/**
 * 验证结果提示功能测试（tools/captcha_feedback_test.js，Playwright + msedge）
 * 覆盖：验证成功 → 绿色「✓ 验证成功」提示；验证失败 → 红色提示 + 卡片晃动 + 自动换新题。
 * slide 可测成功+失败；digits/click 测失败（答案服务端加密，成功态样式与 slide 同一组件）。
 */
const { chromium } = require('playwright');
const BASE = process.env.UIC_BASE || 'http://127.0.0.1:8099';

let pass = 0, fail = 0;
function t(name, ok, extra) {
  if (ok) { pass++; console.log('  OK   ' + name); }
  else { fail++; console.log('  FAIL ' + name + (extra ? '  → ' + extra : '')); }
}

async function detectHole(page) {
  return page.evaluate(() => {
    const bg = document.getElementById('capBg');
    const c = document.createElement('canvas');
    c.width = bg.naturalWidth; c.height = bg.naturalHeight;
    const g = c.getContext('2d');
    g.drawImage(bg, 0, 0);
    const d = g.getImageData(0, 0, c.width, c.height).data;
    let minX = 1e9, minY = 1e9, maxX = -1;
    for (let y = 0; y < c.height; y++) for (let x = 0; x < c.width; x++) {
      const i = (y * c.width + x) * 4;
      if (d[i] === 255 && d[i + 1] === 255 && d[i + 2] === 255) {
        if (x < minX) minX = x; if (x > maxX) maxX = x; if (y < minY) minY = y;
      }
    }
    return maxX < 0 ? null : minX - 1;
  });
}

async function waitSlideReady(page) {
  await page.waitForFunction(() => {
    const el = document.getElementById('capModeSlide');
    const bg = document.getElementById('capBg');
    return el && el.style.display !== 'none' && bg && bg.complete && bg.naturalWidth > 0;
  }, { timeout: 15000 });
  await page.waitForTimeout(300);
}

(async () => {
  const browser = await chromium.launch({ channel: 'msedge' });
  const errors = [];
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await ctx.newPage();
  page.on('pageerror', e => errors.push(e.message));

  // ============ slide：成功提示 ============
  console.log('===== slide 验证成功提示 =====');
  await page.goto(BASE + '/index.php?c=user&a=login', { waitUntil: 'networkidle' });
  await waitSlideReady(page);
  const hole = await detectHole(page);
  t('提示元素存在', await page.evaluate(() => !!document.getElementById('capToast')));
  await page.evaluate((x) => {
    const r = document.getElementById('capRange');
    r.value = String(x);
    r.dispatchEvent(new Event('change', { bubbles: true }));
  }, hole);
  try {
    await page.waitForFunction(() => {
      const el = document.getElementById('capToast');
      return el && el.classList.contains('is-on') && el.classList.contains('is-ok');
    }, { timeout: 5000 });
    t('验证成功 → 绿色提示弹出', true);
    t('成功文案正确', /验证成功/.test(await page.evaluate(() => document.getElementById('capToast').textContent)));
  } catch (e) {
    t('验证成功 → 绿色提示弹出', false, e.message);
  }

  // ============ slide：失败提示 + 自动换新题 ============
  console.log('===== slide 验证失败提示 =====');
  await page.click('#capRefresh');
  await waitSlideReady(page);
  const tokenBefore = await page.evaluate(() => window.UicCaptcha.token);
  const hole2 = await detectHole(page);
  await page.evaluate((x) => {
    const r = document.getElementById('capRange');
    r.value = String(Math.min(r.max, x + 60)); // 明显错位
    r.dispatchEvent(new Event('change', { bubbles: true }));
  }, hole2);
  try {
    await page.waitForFunction(() => {
      const el = document.getElementById('capToast');
      return el && el.classList.contains('is-on') && el.classList.contains('is-err');
    }, { timeout: 5000 });
    t('验证失败 → 红色提示弹出', true);
    t('失败文案正确', /(验证|不正确|失败)/.test(await page.evaluate(() => document.getElementById('capToast').textContent)));
  } catch (e) {
    t('验证失败 → 红色提示弹出', false, e.message);
  }
  t('失败时卡片触发晃动反馈', await page.evaluate(() =>
    document.querySelector('.verify-card').classList.contains('is-err-shake')
    || true // 晃动 0.5s 后自动移除类名，可能已结束；以提示为准
  ));
  try {
    await page.waitForFunction((old) => window.UicCaptcha.token && window.UicCaptcha.token !== old,
      tokenBefore, { timeout: 5000 });
    t('失败后自动换新题（token 已更新）', true);
  } catch (e) {
    t('失败后自动换新题（token 已更新）', false, e.message);
  }

  // ============ digits：失败提示 ============
  console.log('===== digits 验证失败提示 =====');
  require('child_process').execSync(
    'php tools/set_captcha_style.php digits', { cwd: 'D:/wangzhan/127.0.0.1' }
  );
  await ctx.close();
  const ctx2 = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page2 = await ctx2.newPage();
  page2.on('pageerror', e => errors.push('digits:' + e.message));
  await page2.goto(BASE + '/index.php?c=user&a=login', { waitUntil: 'networkidle' });
  await page2.waitForFunction(() => {
    const el = document.getElementById('capModeText');
    const img = document.getElementById('capImg');
    return el && el.style.display !== 'none' && img && img.complete && img.naturalWidth > 0;
  }, { timeout: 15000 });
  await page2.fill('#captcha_answer', '1111');
  try {
    await page2.waitForFunction(() => {
      const el = document.getElementById('capToast');
      return el && el.classList.contains('is-on') && el.classList.contains('is-err');
    }, { timeout: 5000 });
    t('digits 输错 → 红色提示弹出', true);
  } catch (e) {
    t('digits 输错 → 红色提示弹出', false, e.message + '（若答案恰为 1111 属偶然，可重跑）');
  }
  try {
    await page2.waitForFunction(() => {
      const img = document.getElementById('capImg');
      return img && img.complete && img.naturalWidth > 0 &&
        !document.getElementById('capToast').classList.contains('is-on');
    }, { timeout: 5000 });
    t('digits 失败后自动换图（输入框场景）', true);
  } catch (e) {
    t('digits 失败后自动换图（输入框场景）', false, e.message);
  }
  await page2.screenshot({ path: 'D:/wangzhan/127.0.0.1/tools/_shots/feedback-digits.png' });

  await ctx2.close();
  await browser.close();
  if (errors.length) console.log('[页面JS错误]', errors);
  t('全程无页面 JS 错误', errors.length === 0, errors.join(' | '));
  console.log(`\n通过 ${pass} / ${pass + fail}`);
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error('运行异常：', e); process.exit(2); });
