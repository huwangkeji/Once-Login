/**
 * UIC SDK 冒烟测试（无网络依赖，仅验证核心逻辑）
 * 运行：node test/smoke.test.js
 */
'use strict';

const assert = require('assert');
const { Client, ApiError, NetworkError } = require('../index.js');

let passed = 0;
function check(name, fn) {
  try {
    fn();
    passed++;
    console.log('  ✓ ' + name);
  } catch (e) {
    console.error('  ✗ ' + name + ' :: ' + e.message);
    process.exitCode = 1;
  }
}

(async () => {
  console.log('UIC JS SDK 冒烟测试');

  const client = new Client({
    baseUrl: 'http://127.0.0.5',
    appId: 'app_001',
    appSecret: 'secret_001',
    timeout: 10,
  });

  check('Client 默认配置', () => {
    assert.strictEqual(client.config.baseUrl, 'http://127.0.0.5');
    assert.strictEqual(client.config.timeout, 10);
  });

  check('子客户端已创建', () => {
    assert.ok(client.auth && client.user && client.captcha);
  });

  check('getAuthorizeUrl 构建正确', () => {
    const url = client.auth.getAuthorizeUrl('http://app.local/callback', 'state-1');
    assert.ok(url.includes('c=sso&a=authorize'));
    assert.ok(url.includes('app_id=app_001'));
    assert.ok(url.includes('state-1'));
  });

  check('错误类导出', () => {
    assert.ok(ApiError && NetworkError);
    const e = new ApiError('bad', 1001, { k: 1 });
    assert.strictEqual(e.code, 1001);
    assert.deepStrictEqual(e.data, { k: 1 });
  });

  check('URL 尾部斜杠被移除', () => {
    const c2 = new Client({ baseUrl: 'http://127.0.0.5/' });
    const url = c2.auth.getAuthorizeUrl('http://a.local/cb');
    assert.ok(!url.includes('//index.php'));
  });

  console.log(`\n通过 ${passed} 项检查` + (process.exitCode ? '（有失败）' : ''));
})();