/**
 * 通行密钥（Passkey / WebAuthn）端到端测试（Playwright + msedge + CDP 虚拟认证器）
 * tools/passkey_e2e_test.js
 *
 * 目的：验证修复「user_passkeys 建表 1050」后，通行密钥「注册 → 登录」全链路可走通。
 * 覆盖：
 *   A. 以注册接口建立会话（user/register 携带 auto_login；注册接口不校验图形验证码）
 *   B. 通行密钥注册：passkey/register_options → navigator.credentials.create → register_finish
 *   C. 凭证落库：uic_user_passkeys 新增记录（验证前缀一致，不再是裸表 user_passkeys）
 *   D. 退出登录后，以「通行密钥」重新登录：auth_options → navigator.credentials.get → auth_finish
 *   E. 通行密钥登录后的会话有效（user/info uid 一致）
 *
 * 运行前置：
 *   1) php -S 127.0.0.1:8099 -t public   （用 http://localhost:8099 访问，rpId 才是合法域 localhost）
 *   2) npm install playwright            （channel:'msedge'，无需下载 Chromium）
 *   3) node tools/passkey_e2e_test.js
 *
 * 注意：WebAuthn 要求 rpId 为合法域名，IP 字面量（127.0.0.1）会被浏览器判为 invalid domain，
 *       故本测试统一走 http://localhost:8099（rpId 自动推导为 localhost）。
 *       结束时清理临时用户与其凭证。
 */
const { chromium } = require('playwright');
const path = require('path');
const { execSync } = require('child_process');

const BASE = process.env.UIC_BASE || 'http://localhost:8099';
const ROOT = path.resolve(__dirname, '..');

let pass = 0, fail = 0;
function t(name, ok, extra) {
  if (ok) { pass++; console.log('  OK   ' + name); }
  else { fail++; console.log('  FAIL ' + name + (extra ? '  → ' + extra : '')); }
}
function php(code) {
  return execSync('php -r ' + JSON.stringify("require 'app/bootstrap.php'; " + code), { cwd: ROOT }).toString().trim();
}

/** 页面内直连 API（同源 Cookie），等价 App.req 但不受 toast 干扰 */
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
  const uname = 'pk' + String(Date.now()).slice(-9);   // 11 位（3-15 合法），非纯数字
  const pwd   = 'Pk!Passw0rd9';
  const email = uname + '@x.com';

  const errors = [];
  const browser = await chromium.launch({ channel: 'msedge' });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 800 } });
  const page = await ctx.newPage();
  page.on('pageerror', e => errors.push(e.message));

  // CDP 虚拟认证器（平台内置、支持常驻凭证 / UV）
  const cdp = await ctx.newCDPSession(page);
  await cdp.send('WebAuthn.enable', { enableUI: false });
  const { authenticatorId } = await cdp.send('WebAuthn.addVirtualAuthenticator', {
    options: {
      protocol: 'ctap2', transport: 'internal',
      hasResidentKey: true, hasUserVerification: true, isUserVerified: true,
      automaticPresenceSimulation: true
    }
  });

  try {
    await page.goto(BASE + '/index.php?c=user&a=login', { waitUntil: 'networkidle' });
    console.log('[env] rpId=' + (await (await page.request.get(BASE + '/api/index.php?m=passkey&a=status')).json()).data.rp_id +
      '  origin=' + (await page.evaluate(() => location.origin)));

    // ===== A. 注册接口建立会话（auto_login） =====
    console.log('\n===== A. 注册接口建立会话（注册接口不校验图形验证码） =====');
    const regUser = await api(page, 'user', 'register', {
      username: uname, password: pwd, confirm: pwd, email: email, auto_login: '1'
    });
    t('注册并自动登录成功', regUser.code === 0, JSON.stringify(regUser).slice(0, 160));
    const uid = parseInt(php("echo intval(Database::getInstance()->val('SELECT uid FROM uc_members WHERE username=?', ['" + uname + "']));"));
    t('会话有效（user/info）', uid > 0, 'uid=' + uid);

    // ===== B. 通行密钥注册（登录态） =====
    console.log('\n===== B. 通行密钥注册（Passkey.register） =====');
    const regRes = await page.evaluate(async () => {
      if (typeof Passkey === 'undefined') return { code: -1, message: 'Passkey 未定义' };
      try { return await Passkey.register({ label: 'E2E Virtual' }); }
      catch (e) { return { code: -1, message: String(e && e.message || e) }; }
    });
    t('通行密钥注册成功', regRes.code === 0, JSON.stringify(regRes).slice(0, 220));

    // ===== C. 凭证落库（前缀一致的 uic_user_passkeys） =====
    console.log('\n===== C. 凭证落库（uic_user_passkeys） =====');
    const cnt = parseInt(php("echo intval(Database::getInstance()->val('SELECT COUNT(*) FROM user_passkeys WHERE uid=" + uid + "'));"));
    t('uic_user_passkeys 新增该用户凭证', cnt >= 1, 'rows=' + cnt);

    // ===== D. 退出后以通行密钥重新登录 =====
    console.log('\n===== D. 通行密钥登录（退出后 auth_options → get → auth_finish） =====');
    await api(page, 'user', 'logout', {});
    const infoOut = await api(page, 'user', 'info', {});
    t('已退出登录', infoOut.code !== 0, JSON.stringify(infoOut).slice(0, 120));

    const authRes = await page.evaluate(async () => {
      try { return await Passkey.auth({ remember: true }); }
      catch (e) { return { code: -1, message: String(e && e.message || e) }; }
    });
    t('通行密钥登录成功', authRes.code === 0, JSON.stringify(authRes).slice(0, 220));

    // ===== E. 通行密钥登录后会话有效 =====
    console.log('\n===== E. 通行密钥登录后会话有效 =====');
    const infoIn = await api(page, 'user', 'info', {});
    t('通行密钥登录后 user/info uid 一致', infoIn.code === 0 && String(infoIn.data && infoIn.data.uid) === String(uid),
      JSON.stringify(infoIn).slice(0, 180));

  } finally {
    await cdp.send('WebAuthn.removeVirtualAuthenticator', { authenticatorId }).catch(() => {});
    await ctx.close();
    await browser.close();
  }

  if (errors.length) console.log('\n[页面JS错误]', errors);
  t('全程无页面 JS 错误', errors.length === 0, errors.join(' | '));

  // ---------- 清理临时用户 ----------
  php("$db=Database::getInstance(); $uid=intval($db->val('SELECT uid FROM uc_members WHERE username=?', ['" + uname + "'])); " +
      "if($uid){ $db->delete('user_passkeys','uid=?',[$uid]); $db->delete('webauthn_challenges','uid=?',[$uid]); try{ $db->delete('user_profiles','uid=?',[$uid]); }catch(Exception $e){} } " +
      "$db->delete('uc_members','username=?',['" + uname + "']);");

  console.log(`\n通过 ${pass} / ${pass + fail}`);
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error('运行异常：', e); process.exit(2); });
