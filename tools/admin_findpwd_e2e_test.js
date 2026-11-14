/**
 * 后台「忘记密码？」入口 + 管理员找回密码流程 端到端测试（Playwright + msedge）
 * tools/admin_findpwd_e2e_test.js
 *
 * 覆盖：
 *   A. 后台登录页存在「忘记密码？」链接：文案 / href 指向 c=findpwd / 位于密码字段内 / 右对齐
 *   B. 现有登录表单与校验不受影响（账号密码框仍在、错误密码仍被拒）
 *   C. 点击跳转至后台找回密码页，含账号+邮箱+验证码表单
 *   D. 走完流程：获取验证码 → 下一步 → 设新密码 → 确认重置
 *   E. 新密码可登录后台（闭环）；旧密码失效
 *   F. 总开关关闭时入口消失、找回页降级为「请联系超级管理员」
 *
 * 运行前置：
 *   1) php -S 127.0.0.1:8099 -t public
 *   2) npm install playwright
 *   3) node tools/admin_findpwd_e2e_test.js
 *
 * 说明：图形验证码用 RiskEngine::issueTrust() 服务端签发无感信任凭证绕过（HttpOnly 签名 Cookie，
 *       浏览器脚本无法伪造）；验证码通过页面回显读取（需 debug + admin_findpwd_debug 双开，
 *       测试脚本自动开启并在结束时还原）。
 */
const { chromium } = require('playwright');
const path = require('path');
const { execSync } = require('child_process');

const BASE = process.env.UIC_BASE || 'http://localhost:8099';
const ROOT = path.resolve(__dirname, '..');

let pass = 0, fail = 0;
function t(name, ok, extra) {
  if (ok) { pass++; console.log('  OK   ' + name); }
  else { fail++; console.log('  FAIL ' + name + (extra ? '  -> ' + extra : '')); }
}
function php(code) {
  return execSync('php -r ' + JSON.stringify("require 'app/bootstrap.php'; " + code), { cwd: ROOT }).toString().trim();
}
// 页面 Admin.req 走 POST + FormData（m/a 在请求体里，不在 URL 上），故按 postData 匹配
function waitApi(page, action) {
  return page.waitForResponse(r =>
    r.url().includes('/api/index.php') && (r.request().postData() || '').includes(action),
    { timeout: 12000 });
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
  const uname  = 'afp' + String(Date.now()).slice(-9);
  const oldPwd = 'Old!Passw0rd';
  const newPwd = 'New!Passw0rd';
  const email  = uname + '@x.com';

  // 建临时管理员（带邮箱）+ 信任凭证 + 打开联调回显
  php("AdminModel::ensureEmailColumn(); $r = AdminModel::create('" + uname + "', '" + oldPwd +
      "', 'E2E', 'admin', '" + email + "'); echo json_encode($r);");
  const adminId = php("echo intval(Database::getInstance()->val(\"SELECT id FROM admins WHERE username=?\", ['" + uname + "']));");
  const trust   = php("echo RiskEngine::issueTrust('127.0.0.1', 3600);");
  php("Config::set('debug', 1, 'system', '调试模式'); Config::set('admin_findpwd_debug', 1, 'security', '管理员找回验证码回显'); Config::set('admin_findpwd_enabled', 1, 'security', '管理员自助找回密码开关');");
  if (!adminId) { console.error('临时管理员创建失败，测试中止'); process.exit(2); }

  const errors = [];
  const browser = await chromium.launch({ channel: 'msedge' });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  await ctx.addCookies([{ name: 'uic_passive_trust', value: trust, url: BASE }]);
  const page = await ctx.newPage();
  page.on('pageerror', e => errors.push(e.message));

  try {
    // ===== A. 入口文案/位置 =====
    console.log('\n===== A. 后台登录页「忘记密码？」入口 =====');
    await page.goto(BASE + '/admin.php?c=login', { waitUntil: 'networkidle' });
    const link = page.locator('.login-forgot a');
    t('存在「忘记密码？」链接（唯一）', await link.count() === 1, 'count=' + await link.count());
    t('文案为「忘记密码？」', (await link.innerText()).trim() === '忘记密码？', JSON.stringify(await link.innerText()));
    const href = await link.getAttribute('href');
    t('href 指向后台找回密码页（c=findpwd）', /c=findpwd/.test(href || ''), href);

    const layout = await page.evaluate(() => {
      const p = document.getElementById('password');
      const f = document.querySelector('.login-forgot');
      const field = document.getElementById('pwdField');
      return {
        inPwdField: !!(p && f && f.closest('#pwdField') === field),
        afterPwd:   !!(p && f && (p.compareDocumentPosition(f) & Node.DOCUMENT_POSITION_FOLLOWING)),
        textAlign:  getComputedStyle(f).textAlign,
      };
    });
    t('位于密码字段（#pwdField）内', layout.inPwdField, JSON.stringify(layout));
    t('位于密码框下方', layout.afterPwd);
    t('右对齐（符合常见登录页习惯）', layout.textAlign === 'right', layout.textAlign);
    await page.screenshot({ path: ROOT + '/tools/_shots/admin-login-forgot.png' });

    // ===== B. 不影响既有登录流程与校验 =====
    console.log('\n===== B. 现有登录流程未受影响 =====');
    t('账号/密码输入框仍在', await page.locator('#username').count() === 1 && await page.locator('#password').count() === 1);
    t('登录按钮仍在', await page.locator('#loginBtn').count() === 1);
    const badLogin = await api(page, 'admin', 'login', { username: uname, password: 'WrongPass123' });
    t('错误密码仍被拒绝（校验逻辑未变）', badLogin.code !== 0, JSON.stringify(badLogin).slice(0, 120));

    // ===== C. 跳转 =====
    console.log('\n===== C. 点击跳转后台找回密码页 =====');
    await Promise.all([page.waitForURL(/c=findpwd/, { timeout: 8000 }), link.click()]);
    t('跳转到找回密码页（URL 含 c=findpwd）', /c=findpwd/.test(page.url()), page.url());
    t('含管理员账号/邮箱/验证码表单', await page.locator('#fp_username').count() === 1
      && await page.locator('#fp_email').count() === 1
      && await page.locator('#fp_code').count() === 1);
    t('页面副标题为「管理员找回密码」', (await page.locator('.login-brand .subtitle').innerText()).trim() === '管理员找回密码');

    // ===== D. 找回流程 =====
    console.log('\n===== D. 找回流程（验证身份 → 设置新密码） =====');
    await page.fill('#fp_username', uname);
    await page.fill('#fp_email', email);
    const sendResp = await (async () => {
      const rp = waitApi(page, 'findpwd_send');
      await page.click('#fpSend');
      return (await rp).json();
    })();
    t('获取验证码接口成功', sendResp.code === 0, JSON.stringify(sendResp).slice(0, 160));
    const hintText = await page.locator('#fpHint').innerText();
    const m = hintText.match(/\d{6}/);
    t('页面回显 6 位验证码（联调开关下）', !!m, hintText);
    const code = m ? m[0] : '';

    await page.fill('#fp_code', code);
    await page.click('#fpNext');
    t('进入第二步：设置新密码', await page.isVisible('#fpStep2'));
    await page.screenshot({ path: ROOT + '/tools/_shots/admin-findpwd-step2.png' });

    // 弱口令被拒（服务端校验）
    await page.fill('#fp_npwd', 'abc');
    await page.fill('#fp_npwd2', 'abc');
    const weakResp = await (async () => {
      const rp = waitApi(page, 'findpwd_reset');
      await page.click('#fpReset');
      return (await rp).json();
    })();
    t('弱口令被服务端拒绝', weakResp.code !== 0, JSON.stringify(weakResp).slice(0, 160));

    await page.fill('#fp_npwd', newPwd);
    await page.fill('#fp_npwd2', newPwd);
    const resetResp = await (async () => {
      const rp = waitApi(page, 'findpwd_reset');
      await page.click('#fpReset');
      return (await rp).json();
    })();
    t('确认重置成功', resetResp.code === 0, JSON.stringify(resetResp).slice(0, 160));

    // ===== E. 闭环：新密码登录后台 =====
    console.log('\n===== E. 闭环验证 =====');
    await page.goto(BASE + '/admin.php?c=login', { waitUntil: 'networkidle' });
    const loginNew = await api(page, 'admin', 'login', { username: uname, password: newPwd });
    t('新密码可登录后台', loginNew.code === 0, JSON.stringify(loginNew).slice(0, 160));
    await api(page, 'admin', 'logout', {});
    const loginOld = await api(page, 'admin', 'login', { username: uname, password: oldPwd });
    t('旧密码已失效', loginOld.code !== 0, JSON.stringify(loginOld).slice(0, 120));

    // ===== F. 总开关关闭：入口消失 + 页面降级 =====
    console.log('\n===== F. 开关关闭时的降级 =====');
    php("Config::set('admin_findpwd_enabled', 0, 'security', '管理员自助找回密码开关');");
    await page.goto(BASE + '/admin.php?c=login', { waitUntil: 'networkidle' });
    t('开关关闭后登录页不再显示入口', await page.locator('.login-forgot a').count() === 0);
    await page.goto(BASE + '/admin.php?c=findpwd', { waitUntil: 'networkidle' });
    t('找回页显示降级提示（不放假表单）', await page.locator('.login-degraded').count() === 1
      && await page.locator('#fp_username').count() === 0);
    await page.screenshot({ path: ROOT + '/tools/_shots/admin-findpwd-degraded.png' });
    php("Config::set('admin_findpwd_enabled', 1, 'security', '管理员自助找回密码开关');");

  } finally {
    await ctx.close();
    await browser.close();
  }

  if (errors.length) console.log('\n[页面JS错误]', errors);
  t('全程无页面 JS 错误', errors.length === 0, errors.join(' | '));

  // ---------- 清理 ----------
  php("$db = Database::getInstance(); $db->delete('admins','username=?',['" + uname + "']); " +
      "$db->delete('verify_codes','target=?',['" + email + "']); " +
      "Config::set('admin_findpwd_debug', 0, 'security', '管理员找回验证码回显'); " +
      "Config::set('debug', 0, 'system', '调试模式');");

  console.log(`\n通过 ${pass} / ${pass + fail}  (临时管理员 id=${adminId})`);
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error('运行异常：', e); process.exit(2); });
