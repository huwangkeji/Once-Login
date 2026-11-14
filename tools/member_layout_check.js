/**
 * 会员中心布局回归检查（Playwright）
 * tools/member_layout_check.js
 *
 * 目的：验证「个人资料 / 账号安全」页在 PC 与移动端的响应式排版，
 *       重点断言：① 无横向滚动；② 窄屏头部折叠为抽屉菜单；③ 顶部主操作可用；
 *       ④ 全流程无 JS 报错。并输出各断点截图到 tools/_shots/。
 *
 * 运行：node tools/member_layout_check.js
 * 说明：测试用临时账号在结束时删除（非破坏性，不触碰既有数据）。
 */
const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');
const { execSync } = require('child_process');

const BASE = process.env.UIC_BASE || 'http://127.0.0.1';
const ROOT = path.resolve(__dirname, '..');
const SHOTS = path.join(__dirname, '_shots');

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

/** 页面横向溢出检测（返回最宽的溢出元素信息，便于定位） */
async function overflowInfo(page) {
  return await page.evaluate(() => {
    const vw = window.innerWidth;
    const doc = document.documentElement;
    const over = [];
    document.querySelectorAll('body *').forEach(el => {
      const r = el.getBoundingClientRect();
      if (r.width === 0 && r.height === 0) return;
      if (r.right > vw + 1 || r.left < -1) {
        const cs = getComputedStyle(el);
        if (cs.position === 'fixed') return;
        over.push((el.tagName.toLowerCase()) + '.' + (el.className || '').toString().split(' ')[0] +
          ' [' + Math.round(r.left) + '→' + Math.round(r.right) + ']');
      }
    });
    return { scrollWidth: doc.scrollWidth, innerWidth: vw, list: over.slice(0, 6) };
  });
}

(async () => {
  if (!fs.existsSync(SHOTS)) fs.mkdirSync(SHOTS, { recursive: true });

  const uname = 'ly' + String(Date.now()).slice(-9);
  const pwd   = 'Ly!Passw0rd9';
  const email = 'long.address.for.layout.check@example-domain.com';
  const nick  = '墨生的超长昵称用于换行验证ABCDEFGH';
  const bio   = '这是一段用于验证窄屏换行与截断效果的个人简介文案，长度较长以便观察排版表现。';

  const errors = [];
  const browser = await chromium.launch({ channel: 'msedge' });

  try {
    /* ---------- 准备：注册临时账号并写入长文本资料 ---------- */
    const boot = await browser.newContext({ viewport: { width: 1280, height: 800 } });
    const bootPage = await boot.newPage();
    await bootPage.goto(BASE + '/index.php', { waitUntil: 'domcontentloaded' });
    const reg = await api(bootPage, 'user', 'register', {
      username: uname, password: pwd, confirm: pwd, email: email, auto_login: '1'
    });
    t('临时账号注册并登录', reg.code === 0, JSON.stringify(reg).slice(0, 160));
    const up = await api(bootPage, 'user', 'profile', {
      nickname: nick, email: email, mobile: '', gender: '1', birthday: '1995-06-01', bio: bio, avatar: ''
    });
    t('写入长文本资料用于排版验证', up.code === 0, JSON.stringify(up).slice(0, 160));

    const cases = [
      { name: 'pc-1440',            w: 1440, h: 900,  mobile: false },
      { name: 'pc-1024',            w: 1024, h: 800,  mobile: false },
      { name: 'tablet-961',         w: 961,  h: 720,  mobile: true  },
      { name: 'tablet-820',         w: 820,  h: 1180, mobile: true  },
      { name: 'phone-390',          w: 390,  h: 844,  mobile: true  },
      { name: 'phone-land-844x390', w: 844,  h: 390,  mobile: true  },
    ];

    for (const c of cases) {
      const ctx = await browser.newContext({ viewport: { width: c.w, height: c.h }, storageState: await boot.storageState() });
      const page = await ctx.newPage();
      page.on('pageerror', e => errors.push(c.name + ': ' + e.message));

      await page.goto(BASE + '/index.php?c=user&a=profile', { waitUntil: 'networkidle' });
      console.log('\n===== ' + c.name + ' (' + c.w + '×' + c.h + ') =====');

      // 基本渲染
      t('页面标题 / 内容渲染', (await page.textContent('.mc-page__title')).trim() === '个人资料');
      t('身份概览卡存在', await page.locator('.mc-hero').count() === 1);
      t('分组标题 4 个', await page.locator('.mc-group__title').count() === 4);
      t('资料条目 10 条', await page.locator('.mc-row').count() === 10);

      // 横向溢出
      const ov = await overflowInfo(page);
      t('无横向滚动', ov.scrollWidth <= ov.innerWidth + 1,
        'scrollWidth=' + ov.scrollWidth + ' innerWidth=' + ov.innerWidth + ' | ' + ov.list.join(' , '));

      // 头部形态
      const navVisible  = await page.locator('.mh-nav').isVisible();
      const burgerVisible = await page.locator('.mh-burger').isVisible();
      const drawerVisible = await page.locator('.mh-drawer').isVisible();
      const ctaVisible  = await page.locator('.mh-cta').isVisible();
      t('头部形态符合断点', c.mobile ? (burgerVisible && !navVisible) : (navVisible && !drawerVisible),
        'nav=' + navVisible + ' burger=' + burgerVisible + ' drawer=' + drawerVisible);
      t('主操作按钮常驻可见', ctaVisible);

      // 桌面端：分组导航不得被压缩或与右侧操作区重叠
      if (!c.mobile) {
        const navOk = await page.evaluate(() => {
          const nav = document.querySelector('.mh-nav'), right = document.querySelector('.mh-right');
          if (!nav || !right) return false;
          const links = Array.prototype.slice.call(nav.querySelectorAll('a'));
          const need = links.reduce((s, a) => s + a.getBoundingClientRect().width, 0) + 6 + 13;
          const nr = nav.getBoundingClientRect(), rr = right.getBoundingClientRect();
          return nr.width + 2 >= need && nr.right <= rr.left + 1;
        });
        t('桌面导航未被压缩 / 无重叠', navOk);
      }

      // 侧栏形态
      const sideBox = await page.locator('.mc-side').boundingBox();
      const mainBox = await page.locator('.mc-main').boundingBox();
      const stacked = mainBox.y > sideBox.y + sideBox.height - 2;
      t('窄屏已转单栏' + (c.w <= 900 ? '（预期单栏）' : '（预期两栏）'),
        c.w <= 900 ? stacked : !stacked,
        'side.bottom=' + Math.round(sideBox.y + sideBox.height) + ' main.y=' + Math.round(mainBox.y));

      // 抽屉交互（移动端）
      if (c.mobile) {
        await page.click('.mh-burger');
        await page.waitForTimeout(120);
        const menuOpen = await page.locator('.mh-menu').isVisible();
        const mb = menuOpen ? await page.locator('.mh-menu').boundingBox() : null;
        t('抽屉菜单可展开且未溢出视口', menuOpen && mb && mb.x >= -1 && mb.x + mb.width <= c.w + 1,
          'menu=' + JSON.stringify(mb));
        t('抽屉内含分组入口', await page.locator('.mh-menu__grp').count() >= 4);
        const logoutBtn = await page.locator('.mh-menu__act').count();
        t('抽屉内含退出登录', logoutBtn === 1);
        await page.screenshot({ path: path.join(SHOTS, c.name + '-drawer.png') });
        await page.click('.mh-burger');
        await page.waitForTimeout(120);
      }

      // 主操作：编辑资料 → 打开弹窗
      await page.click('.mh-cta');
      await page.waitForTimeout(200);
      t('主操作「编辑资料」可打开弹窗', await page.locator('#editModal.on').count() === 1);
      await page.screenshot({ path: path.join(SHOTS, c.name + '-modal.png') });
      await page.keyboard.press('Escape');

      await page.screenshot({ path: path.join(SHOTS, c.name + '-profile.png'), fullPage: c.w <= 900 });
      await ctx.close();

      /* ---------- 账号安全页 ---------- */
      const ctx2 = await browser.newContext({ viewport: { width: c.w, height: c.h }, storageState: await boot.storageState() });
      const p2 = await ctx2.newPage();
      p2.on('pageerror', e => errors.push(c.name + '/security: ' + e.message));
      await p2.goto(BASE + '/index.php?c=user&a=security', { waitUntil: 'networkidle' });
      const ov2 = await overflowInfo(p2);
      t('账号安全页无横向滚动', ov2.scrollWidth <= ov2.innerWidth + 1,
        'scrollWidth=' + ov2.scrollWidth + ' | ' + ov2.list.join(' , '));
      const cta2 = await p2.locator('.mh-cta').textContent();
      t('安全页主操作为「修改密码」', cta2.trim() === '修改密码', cta2.trim());
      await p2.click('.mh-cta');
      await p2.waitForTimeout(300);
      const editorOpen = await p2.evaluate(() => {
        const e = document.getElementById('pwdEditor');
        return e ? getComputedStyle(e).display !== 'none' : false;
      });
      t('主操作可展开修改密码表单', editorOpen);
      await p2.screenshot({ path: path.join(SHOTS, c.name + '-security.png'), fullPage: c.w <= 900 });
      await ctx2.close();
    }

    await boot.close();
  } finally {
    await browser.close();
  }

  if (errors.length) console.log('\n[页面JS错误]\n' + errors.join('\n'));
  t('全程无页面 JS 错误', errors.length === 0);

  /* ---------- 清理临时账号 ---------- */
  php("$db=Database::getInstance(); $uid=intval($db->val('SELECT uid FROM uc_members WHERE username=?', ['" + uname + "'])); " +
      "if($uid){ $db->delete('user_profiles','uid=?',[$uid]); $db->delete('sessions','uid=?',[$uid]); " +
      "$db->delete('user_passkeys','uid=?',[$uid]); $db->delete('webauthn_challenges','uid=?',[$uid]); } " +
      "$db->delete('uc_members','username=?',['" + uname + "']);");
  console.log('\n截图输出：tools/_shots/*.png');
  console.log('通过 ' + pass + ' / ' + (pass + fail));
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error('运行异常：', e); process.exit(2); });
