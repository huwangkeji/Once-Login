/**
 * 无感验证（passive）端到端测试（Playwright + msedge，tools/passive_e2e_test.js）
 *
 * 覆盖「配置级可定制无感验证」上线后的全链路：
 *   A. 无感模式自动初始化：登录页直接展示 passive 卡片，不强制任何交互式拼图（对用户无感）
 *   B. 真实人类行为 → 服务端评分放行 → 下发信任 Cookie → 登录网关不拦截（code≠406）
 *   C. 信任凭证跨页生效：二次访问登录页，验证卡片被整体隐藏（无需再次验证）
 *   D. 中风险静止会话 → 自动回退到交互式验证码（challenge，而非误杀/拦截）
 *   E. 高风险流量 → 直接拦截（block 哨兵 __passive_block__，网关转 406）
 *
 * 运行前置：
 *   1) 启动开发服务器：php -S 127.0.0.1:8099 -t public   （本测试会自动把 captcha_style 切到 passive）
 *   2) 安装 Playwright：npm install playwright   （使用 channel:'msedge'，无需下载 Chromium）
 *   3) 本机已安装 Microsoft Edge
 *   4) 命令：node tools/passive_e2e_test.js
 *
 * 说明：测试启动时会临时把 captcha_style 设为 passive、login_captcha 置 1，并在结束时恢复原值；
 *      场景 E 会临时切到 custom 阈值以确保高危样本稳定触发 block，结束后恢复。
 */
const { chromium } = require('playwright');
const path = require('path');
const { execSync } = require('child_process');

const BASE = process.env.UIC_BASE || 'http://127.0.0.1:8099';
const SHOT = process.env.UIC_SHOT_DIR || 'D:/wangzhan/127.0.0.1/tools/_shots';
const ROOT = path.resolve(__dirname, '..');

let pass = 0, fail = 0;
function t(name, ok, extra) {
  if (ok) { pass++; console.log('  OK   ' + name); }
  else { fail++; console.log('  FAIL ' + name + (extra ? '  → ' + extra : '')); }
}

// ---- 通过 PHP-CLI 读写配置（避免污染测试环境，结束时恢复）----
function phpConfig(code) {
  return execSync('php -r ' + JSON.stringify("require 'app/bootstrap.php'; " + code), { cwd: ROOT }).toString().trim();
}

// ---- 模拟人类鼠标轨迹：正弦扰动 + 随机抖动 + 变速，避免被判为直线/匀速机器人 ----
async function humanMove(page, ms) {
  const steps = Math.max(30, Math.floor(ms / 40));
  const w = 1280, h = 800;
  for (let i = 0; i < steps; i++) {
    const x = w / 2 + Math.sin(i / 5) * 320 + (Math.random() - 0.5) * 50;
    const y = h / 2 + Math.cos(i / 7) * 180 + (Math.random() - 0.5) * 40;
    await page.mouse.move(x, y, { steps: 2 });
    await page.waitForTimeout(30 + Math.random() * 35);
  }
}

(async () => {
  const fs = require('fs');
  if (!fs.existsSync(SHOT)) fs.mkdirSync(SHOT, { recursive: true });

  // ---------- 配置前置 ----------
  const origStyle = phpConfig("echo Config::get('captcha_style','slide');");
  const origLoginCap = phpConfig("echo Config::get('login_captcha',0);");
  const origIntensity = phpConfig("echo Config::get('passive_verify_intensity','normal');");
  phpConfig("Config::set('captcha_style','passive'); Config::set('login_captcha',1);");

  const errors = [];
  const browser = await chromium.launch({ channel: 'msedge' });

  try {
    // ============ 场景 A + C(跨页)：真实人类行为 → 无感放行 + 信任凭证 ============
    console.log('\n===== A. 无感放行：自动初始化 + 行为评分通过 + 信任 Cookie =====');
    {
      const ctx = await browser.newContext({ viewport: { width: 1280, height: 800 } });
      const page = await ctx.newPage();
      page.on('pageerror', e => errors.push('A:' + e.message));
      await page.goto(BASE + '/index.php?c=user&a=login', { waitUntil: 'networkidle' });

      // A1. 无感模式自动初始化：passive 卡片可见，交互式拼图不展示
      await page.waitForFunction(() => {
        const p = document.getElementById('capModePassive');
        return p && !p.classList.contains('hide') && p.style.display !== 'none';
      }, { timeout: 15000 }).catch(() => {});
      const init = await page.evaluate(() => {
        const p = document.getElementById('capModePassive');
        const s = document.getElementById('capModeSlide');
        return {
          passiveShown: !!(p && !p.classList.contains('hide') && p.style.display !== 'none'),
          slideHidden: !!(s && (s.style.display === 'none' || s.classList.contains('hide'))),
          hasCard: !!document.querySelector('.verify-card'),
        };
      });
      t('无感模式自动初始化（passive 卡片可见）', init.passiveShown, JSON.stringify(init));
      t('默认不展示交互式拼图（对用户无感）', init.slideHidden, JSON.stringify(init));
      t('安全验证卡片已渲染', init.hasCard);

      // A2. 模拟人类轨迹（持续 > collect_ms，确保采集窗口内积累足够点）
      await humanMove(page, 2800);

      // A3. 等待服务端评分放行（文案变为「环境安全，验证通过」）
      try {
        await page.waitForFunction(() => {
          const el = document.getElementById('capPassiveText');
          return el && /验证通过/.test(el.textContent || '');
        }, { timeout: 15000 });
        t('无感验证通过（服务端行为评分放行）', true);
      } catch (e) {
        t('无感验证通过（服务端行为评分放行）', false, e.message);
      }
      await page.screenshot({ path: SHOT + '/passive-passed.png' });

      // A4. 已下发信任凭证 Cookie（跨页无缝放行的基础）
      const cookies = await ctx.cookies();
      const trust = cookies.find(c => c.name === 'uic_passive_trust');
      t('已下发信任凭证 Cookie uic_passive_trust', !!trust, 'cookies=' + cookies.map(c => c.name).join(','));

      // A5. 登录网关接受（非 406 验证码错误）→ 无感放行，未强制交互
      await page.fill('#account', 'passive_e2e_nonexist');
      await page.fill('#password', 'WrongPwd!123');
      await page.check('#agree');
      await page.waitForFunction(() => !document.getElementById('loginBtn').disabled, { timeout: 8000 })
        .catch(() => {});
      const respA = await (async () => {
        const rp = page.waitForResponse(r => r.url().includes('m=user&a=login'), { timeout: 10000 });
        await page.click('#loginBtn');
        return rp.then(r => r.json());
      })();
      t('登录网关接受无感验证（非 406 验证码错误）', respA.code !== 406, JSON.stringify(respA).slice(0, 160));
      t('无感放行：登录未被强制交互式验证码拦截', respA.code !== 406);

      // ============ 场景 C（跨页）：信任凭证二次访问免验证 ============
      console.log('\n===== C. 跨页无感：持信任凭证二次访问登录页免验证 =====');
      await page.goto(BASE + '/index.php?c=user&a=login', { waitUntil: 'networkidle' });
      const reload = await page.evaluate(() => ({
        hasCard: !!document.querySelector('.verify-card'),
        hasPassive: !!document.getElementById('capModePassive'),
      }));
      t('信任凭证生效：二次访问隐藏验证卡片（无需再验证）', !reload.hasCard && !reload.hasPassive, JSON.stringify(reload));

      await ctx.close();
    }

    // ============ 场景 D：中风险静止会话 → 回退交互式（不误杀） ============
    console.log('\n===== D. 中风险静止会话 → 自动回退交互式验证码 =====');
    {
      const ctx = await browser.newContext({ viewport: { width: 1280, height: 800 } });
      const page = await ctx.newPage();
      page.on('pageerror', e => errors.push('D:' + e.message));
      await page.goto(BASE + '/index.php?c=user&a=login', { waitUntil: 'networkidle' });
      // 全程不移动鼠标 → 轨迹为空 → 服务端判定中风险 → 回退到管理员配置的交互式验证码（challenge）
      // 回退形态取决于后台 captcha_fallback：click/icon → 点选(#capModePick)、slide → 滑块(#capModeSlide)、digits/mixed → 字符(#capModeText)
      const fallback = phpConfig("echo Config::get('captcha_fallback','slide');");
      const fbSel = (fallback === 'click' || fallback === 'icon') ? '#capModePick'
                  : (fallback === 'slide') ? '#capModeSlide'
                  : '#capModeText';
      const fell = await page.waitForFunction((sel) => {
        const el = document.querySelector(sel);
        return el && !el.classList.contains('hide') && el.style.display !== 'none';
      }, fbSel, { timeout: 12000 }).then(() => true).catch(() => false);
      t('静止会话自动回退交互式验证（未误杀/拦截）', fell, 'fallback=' + fallback + ' sel=' + fbSel);
      await page.screenshot({ path: SHOT + '/passive-fallback.png' });
      await ctx.close();
    }

    // ============ 场景 E：高风险流量 → 直接拦截（block 哨兵） ============
    console.log('\n===== E. 高风险流量 → 直接拦截（分级拦截 block） =====');
    {
      // 临时切到 custom 阈值，确保高危样本稳定触发 block（仅验证拦截响应路径，与阈值数值无关）
      phpConfig("Config::set('passive_verify_intensity','custom'); Config::set('passive_score_pass',10); Config::set('passive_score_challenge',30); Config::set('passive_score_block',50);");
      const ctx = await browser.newContext({ viewport: { width: 1280, height: 800 } });
      const page = await ctx.newPage();
      page.on('pageerror', e => errors.push('E:' + e.message));
      await page.goto(BASE + '/index.php?c=user&a=login', { waitUntil: 'networkidle' });
      const block = await page.evaluate(async () => {
        const issue = await (await fetch('/api/index.php?m=captcha&a=issue&style=passive', {
          method: 'POST', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })).json();
        const token = issue.data && issue.data.token;
        // 高危载荷：极短耗时 + 直线 + 无环境指纹（headless 特征）
        const traj = [];
        for (let i = 0; i < 8; i++) traj.push([i * 5, 20 + i * 25, 50]);
        const answer = JSON.stringify({ traj, env: {} });
        const f = new FormData();
        f.append('token', token); f.append('answer', answer);
        const res = await (await fetch('/api/index.php?m=captcha&a=verify', {
          method: 'POST', body: f, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })).json();
        return { code: res.code, msg: res.message };
      });
      t('高风险流量被直接拦截（block 哨兵 __passive_block__）',
        block.code === 406 && block.msg === '__passive_block__', JSON.stringify(block));
      await ctx.close();
      // 恢复阈值强度
      phpConfig("Config::set('passive_verify_intensity','" + origIntensity + "');");
    }

  } finally {
    await browser.close();
  }

  if (errors.length) console.log('\n[页面JS错误]', errors);
  t('全程无页面 JS 错误', errors.length === 0, errors.join(' | '));
  console.log(`\n通过 ${pass} / ${pass + fail}`);

  // ---------- 配置恢复 ----------
  phpConfig("Config::set('captcha_style','" + origStyle + "'); Config::set('login_captcha'," + origLoginCap + ");");
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error('运行异常：', e); process.exit(2); });
