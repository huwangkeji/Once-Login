/**
 * 「忘记密码？」入口 + 找回密码流程 端到端测试（Playwright + msedge）
 * tools/findpwd_e2e_test.js
 *
 * 覆盖：
 *   A. 登录页存在「忘记密码？」链接：文案 / href 指向 c=user&a=findpwd / 位于密码字段区域 / 右对齐
 *   B. 密码登录模式可见、验证码登录模式隐藏（不干扰原交互）
 *   C. 点击后跳转到找回密码页，且页面含「验证身份」表单（#target/#btnSend）
 *   D. 走完找回流程：填账号+验证码 → 下一步 → 设新密码 → 确认重置
 *   E. 用新密码可正常登录（流程闭环）；原密码登录流程未受影响
 *
 * 运行前置：
 *   1) php -S 127.0.0.1:8099 -t public
 *   2) npm install playwright
 *   3) node tools/findpwd_e2e_test.js
 *
 * 说明：为避免自动化求解图形验证码，测试用 RiskEngine::issueTrust() 直接签发无感信任凭证
 *       （HttpOnly Cookie 无法由浏览器脚本伪造，此为服务端签发，等同已通过无感验证）；
 *       并用 VerifyModel::issue() 直接签发找回验证码（库中为加盐哈希，不可逆读）。
 *       结束时清理临时用户与其验证码记录。
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
  const uname  = 'fp' + String(Date.now()).slice(-9);
  const oldPwd = 'Old!Passw0rd';
  const newPwd = 'New!Passw0rd';
  const email  = uname + '@x.com';

  // 建临时用户 + 签发信任凭证（绑定 127.0.0.1）+ 签发找回验证码
  php("$r=UserService::register(['username'=>'" + uname + "','password'=>'" + oldPwd +
      "','confirm'=>'" + oldPwd + "','email'=>'" + email + "','nickname'=>'E2E FP'], false); echo json_encode($r);");
  const uid   = php("echo intval(Database::getInstance()->val('SELECT uid FROM uc_members WHERE username=?', ['" + uname + "']));");
  const trust = php("echo RiskEngine::issueTrust('127.0.0.1', 3600);");
  const code  = php("echo VerifyModel::issue('" + email + "', 'findpwd');");

  const errors = [];
  const browser = await chromium.launch({ channel: 'msedge' });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 800 } });
  await ctx.addCookies([{ name: 'uic_passive_trust', value: trust, url: BASE }]);
  const page = await ctx.newPage();
  page.on('pageerror', e => errors.push(e.message));

  try {
    await page.goto(BASE + '/index.php?c=user&a=login', { waitUntil: 'networkidle' });

    // ===== A. 链接存在 / 文案 / href / 位置 / 对齐 =====
    console.log('\n===== A. 登录页「忘记密码？」入口 =====');
    const link = page.locator('.auth-forgot a');
    t('存在「忘记密码？」链接（唯一）', await link.count() === 1, 'count=' + await link.count());
    t('文案为「忘记密码？」', (await link.innerText()).trim() === '忘记密码？', JSON.stringify(await link.innerText()));
    const href = await link.getAttribute('href');
    t('href 指向找回密码页（c=user&a=findpwd）', /c=user&a=findpwd/.test(href || ''), href);

    const layout = await page.evaluate(() => {
      const p = document.getElementById('password');
      const f = document.querySelector('.auth-forgot');
      return {
        sameField: !!(p && f && p.closest('.auth-field') === f.closest('.auth-field')), // 位于密码框同一字段区域
        afterPwd: !!(p && f && (p.compareDocumentPosition(f) & Node.DOCUMENT_POSITION_FOLLOWING)), // 在密码框之后
        textAlign: getComputedStyle(f).textAlign,
      };
    });
    t('链接位于密码框所在字段区域内', layout.sameField, JSON.stringify(layout));
    t('位于密码框之后（下方）', layout.afterPwd);
    t('右对齐（符合常见登录页习惯）', layout.textAlign === 'right', layout.textAlign);
    await page.screenshot({ path: ROOT + '/tools/_shots/login-forgot-link.png' });

    // ===== B. 仅密码登录模式显示 =====
    console.log('\n===== B. 展示交互（随登录模式切换） =====');
    t('密码登录模式：可见', await link.isVisible());
    await page.click('#tab-code');
    t('验证码登录模式：隐藏（不干扰）', !(await link.isVisible()));
    await page.click('#tab-pwd');
    t('切回密码模式：恢复可见', await link.isVisible());

    // ===== C. 点击跳转至找回密码页 =====
    console.log('\n===== C. 点击跳转 =====');
    await Promise.all([page.waitForURL(/a=findpwd/, { timeout: 8000 }), link.click()]);
    t('跳转到找回密码页（URL 含 a=findpwd）', /a=findpwd/.test(page.url()), page.url());
    t('找回密码页含「验证身份」表单', await page.locator('#target').count() === 1 && await page.locator('#btnSend').count() === 1);
    t('页面标题为「找回密码」', (await page.locator('.auth-card__title').innerText()).trim() === '找回密码');

    // ===== D. 走完找回流程 =====
    console.log('\n===== D. 找回流程（验证身份 → 设置新密码） =====');
    await page.fill('#target', email);
    await page.fill('#code', code);
    await page.check('#agree');
    await page.waitForFunction(() => !document.getElementById('btnNext').disabled, { timeout: 8000 }).catch(() => {});
    await page.click('#btnNext');
    t('进入第二步：设置新密码', await page.isVisible('#step2'));
    await page.fill('#npwd', newPwd);
    await page.fill('#npwd2', newPwd);
    const resetResp = await (async () => {
      const rp = page.waitForResponse(r => r.url().includes('a=findpwd_reset'), { timeout: 10000 });
      await page.click('#btnReset');
      return (await rp).json();
    })();
    t('确认重置成功', resetResp.code === 0, JSON.stringify(resetResp).slice(0, 160));
    await page.screenshot({ path: ROOT + '/tools/_shots/findpwd-reset.png' });

    // ===== E. 新密码登录（闭环）+ 旧密码失效 =====
    console.log('\n===== E. 闭环验证 =====');
    await page.goto(BASE + '/index.php?c=user&a=login', { waitUntil: 'networkidle' });
    const loginNew = await api(page, 'user', 'login', { account: uname, password: newPwd, remember: '1' });
    t('新密码可正常登录（流程闭环）', loginNew.code === 0, JSON.stringify(loginNew).slice(0, 160));
    await api(page, 'user', 'logout', {});
    const loginOld = await api(page, 'user', 'login', { account: uname, password: oldPwd });
    t('旧密码已失效', loginOld.code !== 0, JSON.stringify(loginOld).slice(0, 120));

  } finally {
    await ctx.close();
    await browser.close();
  }

  if (errors.length) console.log('\n[页面JS错误]', errors);
  t('全程无页面 JS 错误', errors.length === 0, errors.join(' | '));

  // ---------- 清理临时用户 ----------
  php("$db=Database::getInstance(); $uid=intval($db->val('SELECT uid FROM uc_members WHERE username=?', ['" + uname + "'])); " +
      "if($uid){ $db->delete('user_passkeys','uid=?',[$uid]); try{ $db->delete('user_profiles','uid=?',[$uid]); }catch(Exception $e){} } " +
      "$db->delete('uc_members','username=?',['" + uname + "']); $db->delete('verify_codes','target=?',['" + email + "']);");

  console.log(`\n通过 ${pass} / ${pass + fail}  (临时用户 uid=${uid})`);
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error('运行异常：', e); process.exit(2); });
