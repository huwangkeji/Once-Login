<?php
/**
 * 【已废弃 / DEPRECATED】
 * 本文件已被 sdk/examples/ 下的官方示例取代（quickstart / basic_usage /
 * sso_callback / captcha_flow / login_code / passkey_flow / admin_products /
 * sso_full_flow）。此处仅作历史参考，请勿在新项目中直接使用。
 *
 * 第三方站点接入 UIC 的演示（可直接跑、对照修改）
 * ----------------------------------------------------
 * 1. 在 UIC 后台「应用管理」新建应用，得到 AppID / AppSecret / 回调地址。
 * 2. 把下面配置改成你自己的。
 * 3. 访问本页：点击登录 → 跳 UIC → 回跳带 ticket → 自动换 Token + 取资料。
 *
 * 注意：本文件与 UIC 主程序无关，仅作接入示范，可放在任意站点。
 */

require_once __DIR__ . '/UicClient.php';

// ====== 配置区（改成你自己的）======
$UIC_API   = 'http://127.0.0.5:8080/api/index.php';  // UIC 的 Open API 地址
$APP_ID    = 'app_demo_001';                            // 在 UIC 后台创建的应用 AppID
$APP_SECRET= 'secret_demo_001';                         // 应用密钥
$MY_HOME   = 'http://127.0.0.5:8080/sdk/demo.php';     // 本页地址（作为回跳）
// ====================================

session_start();
$client = new UicClient($UIC_API, $APP_ID, $APP_SECRET, $MY_HOME);

// 回跳：UIC 带 ?ticket=xxx 回来
if (!empty($_GET['ticket'])) {
    $ex = $client->exchangeTicket($_GET['ticket']);
    if ($ex['ok']) {
        $_SESSION['uic_token'] = $ex['token'];
        $user = $client->getUserInfo($ex['token']);
        $_SESSION['uic_user']  = $user;
        header('Location: ' . $MY_HOME);   // 去掉 URL 上的 ticket
        exit;
    } else {
        $err = $ex['message'];
    }
}

// 退出
if (isset($_GET['act']) && $_GET['act'] === 'logout' && !empty($_SESSION['uic_token'])) {
    $client->logout($_SESSION['uic_token']);
    unset($_SESSION['uic_token'], $_SESSION['uic_user']);
    header('Location: ' . $MY_HOME);
    exit;
}

$loginUrl = $client->buildLoginUrl($MY_HOME);
$user = $_SESSION['uic_user'] ?? null;
?>
<!doctype html>
<html lang="zh-CN"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>UIC 接入演示</title>
<style>
 body{font-family:system-ui,Segoe UI,Arial;max-width:640px;margin:40px auto;padding:0 16px;color:#222}
 .card{border:1px solid #dfe8f7;border-radius:12px;padding:18px;margin-bottom:14px}
 a.btn{display:inline-block;background:#2563eb;color:#fff;padding:8px 16px;border-radius:8px;text-decoration:none}
 pre{background:#f2f6fc;padding:12px;border-radius:8px;overflow:auto}
 .err{color:#dc2626}
</style></head>
<body>
  <h2>统一身份认证中心 · 第三方接入演示</h2>

  <?php if (!empty($err)): ?><div class="card err">换取失败：<?= htmlspecialchars($err) ?></div><?php endif; ?>

  <?php if ($user): ?>
    <div class="card">
      <h3>已登录 ✅</h3>
      <pre><?= htmlspecialchars(json_encode($user, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre>
      <p><a class="btn" href="?act=logout">单点退出</a></p>
    </div>
  <?php else: ?>
    <div class="card">
      <p>你尚未登录。点击下方按钮跳转到统一认证中心登录：</p>
      <p><a class="btn" href="<?= htmlspecialchars($loginUrl) ?>">使用统一账号登录</a></p>
    </div>
  <?php endif; ?>

  <div class="card">
    <h3>接入三步</h3>
    <ol>
      <li>后台创建应用 → 拿到 AppID / AppSecret；</li>
      <li><code>new UicClient($api, $appId, $secret)</code> 生成登录 URL；</li>
      <li>回跳时用 <code>ticket</code> 调 <code>exchangeTicket()</code> 换 Token，再 <code>getUserInfo()</code> 取资料。</li>
    </ol>
  </div>
</body></html>
