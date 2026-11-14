<?php
/**
 * 示例：通行密钥 / WebAuthn 流程（m=passkey）
 * ----------------------------------------------------
 * WebAuthn 的「注册 / 认证」核心仪式（ceremony）必须由浏览器完成
 * （navigator.credentials.create / .get），服务端只负责发放 challenge
 * 与校验断言。本示例演示：探测可用性 + 获取注册/认证选项。
 *
 * 运行：php sdk/examples/passkey_flow.php
 */
require_once __DIR__ . '/_helper.php';

// 1) 探测当前访问域是否可用于 WebAuthn（IP / 非 https / 域不匹配会失败）
$status = uic_api_call('passkey', 'status');
uic_dump('passkey/status（域可用性探测）', $status);

if (($status['data']['enabled'] ?? 0) != 1) {
    echo "通行密钥功能未启用（后台「安全与验证」开启 passkey_enabled）。\n";
    exit;
}
if (($status['data']['domain_ok'] ?? 0) != 1) {
    echo "当前域不可用：" . ($status['data']['domain_reason'] ?? '') . "\n";
    echo "提示：" . ($status['data']['domain_hint'] ?? '') . "\n";
    echo "WebAuthn 要求 RP ID 为合法域名，且需安全上下文（https 或 http://localhost）。\n";
    exit;
}

// 2) 获取注册选项（需用户已登录，浏览器据此调用 navigator.credentials.create）
$regOpts = uic_api_call('passkey', 'register_options');
uic_dump('passkey/register_options（注册 challenge）', $regOpts);

// 3) 获取认证选项（公开，浏览器据此调用 navigator.credentials.get）
$authOpts = uic_api_call('passkey', 'auth_options');
uic_dump('passkey/auth_options（认证 challenge）', $authOpts);

echo <<<'JS'

前端关键片段（浏览器，配合 public/assets/passkey.js）：
------------------------------------------------------------
// 注册：拿到 register_options 后
const opts = await fetch('api/index.php?m=passkey&a=register_options').then(r=>r.json());
const cred = await navigator.credentials.create({ publicKey: opts.data });
// 将 cred 的 rawId / clientDataJSON / attestationObject 回传：
await fetch('api/index.php?m=passkey&a=register_finish', {method:'POST',
  body: new URLSearchParams({ tx: opts.data.tx, client_data: b64(cred.response.clientDataJSON),
    attestation: b64(cred.response.attestationObject), label: '我的手机' })});

// 认证：拿到 auth_options 后
const o = await fetch('api/index.php?m=passkey&a=auth_options').then(r=>r.json());
const ass = await navigator.credentials.get({ publicKey: o.data });
// 回传 credential_id / clientDataJSON / authenticatorData / signature 到 auth_finish
JS;
