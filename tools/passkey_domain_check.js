/**
 * 通行密钥「访问域可用性」回归（Playwright）
 * tools/passkey_domain_check.js
 *
 * 背景：WebAuthn 要求 RP ID 必须是「合法域名」且页面处于安全上下文。
 *       用 IP（127.0.0.1 / 局域网 IP）访问时浏览器会直接抛
 *       SecurityError: "This is an invalid domain."，且 rpId 一定不能是 IP。
 *
 * 本测试覆盖两条路：
 *   A. http://127.0.0.1:8099 —— 应「优雅降级」：给出中文指引、禁用添加按钮，
 *      不再把浏览器英文原报错甩给用户，也不再提示「浏览器不支持」。
 *   B. http://localhost:8099 —— 应完全正常：无降级提示、按钮可用，
 *      并能用 CDP 虚拟认证器真正走通「注册 → 列表 → 删除」。
 *
 * 运行前置：php -S 127.0.0.1:8099 -t public   （必须同时用 localhost 与 127.0.0.1 访问）
 * 运行：node tools/passkey_domain_check.js
 */
const { chromium } = require('playwright');
const path = require('path');
const { execSync } = require('child_process');

const IP_BASE   = 'http://127.0.0.1:8099';
const HOST_BASE = 'http://localhost:8099';
const ROOT = path.resolve(__dirname, '..');

let pass = 0, fail = 0;
function t(name, ok, extra) {
  if (ok) { pass++; console.log('  OK   ' + name); }
  else { fail++; console.log('  FAIL ' + name + (extra ? '  → ' + extra : '')); }
}
function php(code) {
  return execSync('php -r ' + JSON.stringify("require 'app/bootstrap.php'; " + code), { cwd: ROOT }).toString().trim();
}
async function api(page, m, a, data) {
  return await page.evaluate(async ({ m, a, data }) => {
    const f = new FormData(); f.append('m', m); f.append('a', a);
    Object.keys(data || {}).forEach(k => f.append(k, data[k]));
    const r = await fetch('/api/index.php?m=' + m + '&a=' + a, {
      method: 'POST', body: f, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    return await r.json();
  }, { m, a, data });
}

(async () => {
  const uname = 'pkd' + String(Date.now()).slice(-8);
  const pwd   = 'Pkd!Passw0rd9';
  const errors = [];
  const browser = await chromium.launch({ channel: 'msedge' });

  try {
    /* ---------- 准备：在 localhost 上注册临时账号（跨源各自登录） ---------- */
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const page = await ctx.newPage();
    page.on('pageerror', e => errors.push('localhost: ' + e.message));

    // 同一个数据库；注册接口不校验图形验证码，auto_login 建立会话
    await page.goto(HOST_BASE + '/index.php', { waitUntil: 'domcontentloaded' });
    const reg = await api(page, 'user', 'register', {
      username: uname, password: pwd, confirm: pwd, email: uname + '@x.com', auto_login: '1'
    });
    t('临时账号注册并登录（localhost）', reg.code === 0, JSON.stringify(reg).slice(0, 140));
    const state = await ctx.storageState();

    /* ================= A. IP 访问：应优雅降级 ================= */
    console.log('\n===== A. http://127.0.0.1:8099（IP 访问，浏览器禁止通行密钥） =====');
    const ipCtx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const ipPage = await ipCtx.newPage();
    ipPage.on('pageerror', e => errors.push('127.0.0.1: ' + e.message));

    await ipPage.goto(IP_BASE + '/index.php?c=user&a=login', { waitUntil: 'networkidle' });
    const ipLogin = await ipPage.evaluate(() => {
      const acc = document.getElementById('account');
      return {
        autocomplete: acc ? acc.getAttribute('autocomplete') : '',
        passkeyBtnRendered: !!document.getElementById('passkeyBtn'),
      };
    });
    t('登录页不再输出 webauthn 自动填充提示', ipLogin.autocomplete === 'username', ipLogin.autocomplete);
    t('登录页不渲染通行密钥按钮', ipLogin.passkeyBtnRendered === false);

    // 该源未登录 → 直接用注册接口在 IP 源建立独立会话（注册不校验图形验证码；登录接口有验证码）
    const ipUser = uname + 'b';
    const ipReg = await api(ipPage, 'user', 'register', {
      username: ipUser, password: pwd, confirm: pwd, email: ipUser + '@x.com', auto_login: '1'
    });
    t('IP 源建立独立会话', ipReg.code === 0, JSON.stringify(ipReg).slice(0, 140));

    const st = await ipPage.evaluate(async () => {
      const r = await fetch('/api/index.php?m=passkey&a=status');
      return await r.json();
    });
    t('status 报告域不可用', st.code === 0 && st.data.domain_ok === 0 && st.data.domain_issue === 'ip',
      JSON.stringify(st.data));
    t('status 附带中文原因与修复指引',
      /IP/.test(st.data.domain_reason || '') && /localhost|HTTPS/.test(st.data.domain_hint || ''),
      (st.data.domain_reason || '') + ' | ' + (st.data.domain_hint || ''));

    await ipPage.goto(IP_BASE + '/index.php?c=user&a=security', { waitUntil: 'networkidle' });
    const ui = await ipPage.evaluate(() => {
      const n = document.getElementById('pkDomainNotice');
      const b = document.getElementById('pkAddBtn');
      const u = document.getElementById('pkUnsupported');
      return {
        notice: !!n && getComputedStyle(n).display !== 'none',
        noticeText: n ? n.textContent.replace(/\s+/g, ' ').trim() : '',
        btnDisabled: !!b && b.disabled,
        btnTitle: b ? (b.getAttribute('title') || '') : '',
        unsupportedShown: !!u && getComputedStyle(u).display !== 'none',
      };
    });
    t('安全中心渲染域不可用提示', ui.notice, ui.noticeText.slice(0, 60));
    t('提示含中文原因 + 修复指引', /不允许在 IP 上使用/.test(ui.noticeText) && /localhost/.test(ui.noticeText));
    t('「添加通行密钥」按钮被禁用', ui.btnDisabled);
    t('按钮 title 给出原因', /IP/.test(ui.btnTitle), ui.btnTitle.slice(0, 40));
    t('不再误报「浏览器不支持通行密钥」', ui.unsupportedShown === false);

    // 直接调用 API 也应返回中文说明，而不是让浏览器抛英文错
    const ro = await ipPage.evaluate(async () => {
      const f = new FormData(); f.append('m', 'passkey'); f.append('a', 'register_options');
      const r = await fetch('/api/index.php?m=passkey&a=register_options', {
        method: 'POST', body: f, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      return await r.json();
    });
    t('register_options 被拦截并返回中文原因', ro.code !== 0 && /IP/.test(ro.message || ''), JSON.stringify(ro).slice(0, 160));

    // 前端模块也应拒绝并给出中文（而不是 "This is an invalid domain."）
    await ipPage.addScriptTag({ url: '/assets/passkey.js' });
    const fe = await ipPage.evaluate(async () => {
      const sup = Passkey.isSupported();
      let msg = '';
      try { await Passkey.register({ label: 'x' }); } catch (e) { msg = String(e && e.message || e); }
      return { sup, msg, issue: Passkey.domainIssue() };
    });
    t('前端 isSupported() 在 IP 源上为 false', fe.sup === false, 'issue=' + fe.issue);
    t('前端抛中文提示而非英文原报错',
      /IP 地址/.test(fe.msg) && !/invalid domain/i.test(fe.msg), fe.msg.slice(0, 70));

    await ipPage.screenshot({ path: path.join(__dirname, '_shots', 'passkey-ip-degraded.png') });
    await ipCtx.close();

    /* ================= B. localhost 访问：应完全正常 ================= */
    console.log('\n===== B. http://localhost:8099（合法域名，应正常） =====');
    const hCtx = await browser.newContext({ viewport: { width: 1280, height: 900 }, storageState: state });
    const hPage = await hCtx.newPage();
    hPage.on('pageerror', e => errors.push('localhost: ' + e.message));

    // CDP 虚拟认证器（平台内置，支持常驻凭证 / UV）
    const cdp = await hCtx.newCDPSession(hPage);
    await cdp.send('WebAuthn.enable', { enableUI: false });
    const { authenticatorId } = await cdp.send('WebAuthn.addVirtualAuthenticator', {
      options: {
        protocol: 'ctap2', transport: 'internal',
        hasResidentKey: true, hasUserVerification: true, isUserVerified: true,
        automaticPresenceSimulation: true
      }
    });

    await hPage.goto(HOST_BASE + '/index.php?c=user&a=profile', { waitUntil: 'networkidle' });
    const logged = await hPage.evaluate(() => !!document.querySelector('.mc-hero'));
    t('localhost 源以既有会话登录成功', logged);

    const st3 = await hPage.evaluate(async () => {
      const r = await fetch('/api/index.php?m=passkey&a=status');
      return await r.json();
    });
    t('status 报告域可用（rp_id=localhost）', st3.code === 0 && st3.data.domain_ok === 1 && st3.data.rp_id === 'localhost',
      JSON.stringify(st3.data));

    await hPage.goto(HOST_BASE + '/index.php?c=user&a=security', { waitUntil: 'networkidle' });
    const ui2 = await hPage.evaluate(() => {
      const n = document.getElementById('pkDomainNotice');
      const b = document.getElementById('pkAddBtn');
      return {
        notice: !!n && getComputedStyle(n).display !== 'none',
        btnDisabled: !!b && b.disabled,
        hasAddBtn: !!b,
      };
    });
    t('localhost 上无降级提示', ui2.notice === false);
    t('「添加通行密钥」按钮可用', ui2.hasAddBtn && ui2.btnDisabled === false);

    // 真正跑一次注册 → 列表 → 删除
    const regRes = await hPage.evaluate(async () => {
      try { return await Passkey.register({ label: 'CDP 虚拟密钥' }); }
      catch (e) { return { code: -1, message: String(e && e.message || e) }; }
    });
    t('通行密钥注册成功（localhost）', regRes.code === 0, JSON.stringify(regRes).slice(0, 160));

    const listRes = await hPage.evaluate(async () => await Passkey.list());
    const items = (listRes && listRes.data && listRes.data.items) || [];
    t('列表中出现新密钥', listRes.code === 0 && items.length >= 1, 'n=' + items.length);
    if (items.length) {
      const delRes = await hPage.evaluate(async (id) => await Passkey.delete(id), items[0].id);
      t('删除通行密钥成功', delRes.code === 0, JSON.stringify(delRes).slice(0, 140));
    }

    // 登录页检查须用「未登录」上下文（已登录访问登录页会 302 到首页）
    const anonCtx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const anon = await anonCtx.newPage();
    await anon.goto(HOST_BASE + '/index.php?c=user&a=login', { waitUntil: 'networkidle' });
    const autocomplete2 = await anon.evaluate(() => {
      const acc = document.getElementById('account');
      return acc ? acc.getAttribute('autocomplete') : '(no #account)';
    });
    t('localhost 登录页保留 webauthn 自动填充', /username webauthn/.test(autocomplete2), autocomplete2);
    await anonCtx.close();

    await cdp.send('WebAuthn.removeVirtualAuthenticator', { authenticatorId }).catch(() => {});
    await hCtx.close();
    await ctx.close();
  } finally {
    await browser.close();
  }

  if (errors.length) console.log('\n[页面JS错误]\n' + errors.join('\n'));
  t('全程无页面 JS 错误', errors.length === 0);

  php("$db=Database::getInstance(); " +
      "foreach (['" + uname + "', '" + uname + "b'] as $n) { " +
      "  $uid=intval($db->val('SELECT uid FROM uc_members WHERE username=?', [$n])); " +
      "  if($uid){ $db->delete('user_passkeys','uid=?',[$uid]); $db->delete('webauthn_challenges','uid=?',[$uid]); " +
      "  $db->delete('user_profiles','uid=?',[$uid]); $db->delete('sessions','uid=?',[$uid]); } " +
      "  $db->delete('uc_members','username=?',[$n]); " +
      "}");
  console.log('\n通过 ' + pass + ' / ' + (pass + fail));
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error('运行异常：', e); process.exit(2); });
