<?php
/**
 * =============================================================
 *  统一身份认证中心 · 安装向导（参照 UCenter 安装流程）
 *
 *  流程：环境检查 → 数据库配置 → 连接检测 → 建表 → 创建管理员 → 写入安装锁
 *  地址：/install.php
 *
 *  安装完成后请删除本文件（页面上也提供一键删除）。
 *  安装锁：data/install.lock，存在时拒绝再次安装，防止被人重放覆盖数据。
 * =============================================================
 */
define('UIC_INSTALLING', true);
require_once dirname(__DIR__) . '/app/bootstrap.php';

// 安装阶段不依赖数据库，屏蔽可能触发连接的逻辑
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

// M4：页面级随机令牌，AJAX 必须携带（安装阶段无登录态，以此防跨站请求伪造）
if (empty($_SESSION['install_token'])) {
    $_SESSION['install_token'] = bin2hex(random_bytes(16));
}
$installToken = (string)$_SESSION['install_token'];

function install_token_ok()
{
    $sess = (string)(isset($_SESSION['install_token']) ? $_SESSION['install_token'] : '');
    $post = (string)(isset($_POST['install_token']) ? $_POST['install_token'] : '');
    return $sess !== '' && $post !== '' && hash_equals($sess, $post);
}

$ROOT   = dirname(__DIR__);
$credF  = $ROOT . '/data/db_cred.php';
$lockF  = $ROOT . '/data/install.lock';
$schema = $ROOT . '/data/schema.sql';

$step = isset($_GET['step']) ? preg_replace('/[^a-z0-9_]/', '', strtolower($_GET['step'])) : '1';

// =============================================================
//  AJAX 接口
// =============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    $act = (string)($_POST['ajax'] ?? '');

    // M4：所有 AJAX 接口（test/install/remove）统一 CSRF 校验
    if (!install_token_ok()) {
        echo json_encode(['ok' => false, 'msg' => '安全校验失败，请刷新页面后重试'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- 测试数据库连接 ----
    if ($act === 'test') {
        $cfg = read_form_cfg();
        $err = validate_cfg($cfg);
        if ($err) {
            echo json_encode(['ok' => false, 'msg' => $err], JSON_UNESCAPED_UNICODE);
            exit;
        }
        try {
            $dsn = sprintf('mysql:host=%s;port=%d;charset=%s', $cfg['host'], $cfg['port'], $cfg['charset']);
            $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 6,
            ]);
            $ver = $pdo->query('SELECT VERSION()')->fetchColumn();

            // 库是否存在；不存在则尝试创建（需要 CREATE 权限）
            $exists = $pdo->prepare(
                "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = ?"
            );
            $exists->execute([$cfg['dbname']]);
            $hasDb = intval($exists->fetchColumn()) > 0;
            $created = false;
            if (!$hasDb) {
                try {
                    $pdo->exec('CREATE DATABASE `' . str_replace('`', '', $cfg['dbname']) . '` '
                        . 'DEFAULT CHARACTER SET ' . preg_replace('/[^a-z0-9_]/i', '', $cfg['charset']));
                    $created = true;
                } catch (PDOException $e) {
                    echo json_encode([
                        'ok'    => false,
                        'msg'   => '连接成功，但无法创建数据库「' . $cfg['dbname'] . '」。'
                            . '请手动创建该库后再试（当前账号缺少 CREATE 权限）。',
                        'ver'   => $ver,
                    ], JSON_UNESCAPED_UNICODE);
                    exit;
                }
            }
            echo json_encode([
                'ok'      => true,
                // 注意：中文紧跟变量时必须写 {$ver}。PHP 的变量名允许 0x80-0xFF 字节，
                // 直接写 "$ver），…" 会把全角括号连同后面文字一起吞进变量名。
                'msg'     => $created
                    ? "连接成功（MySQL {$ver}），已自动创建数据库 {$cfg['dbname']}"
                    : "连接成功（MySQL {$ver}），数据库 {$cfg['dbname']} 已存在",
                'ver'     => $ver,
                'created' => $created,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        } catch (PDOException $e) {
            echo json_encode([
                'ok'  => false,
                'msg' => Database::friendlyError($e, $cfg),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    // ---- 执行安装 ----
    if ($act === 'install') {
        if (file_exists($lockF)) {
            echo json_encode(['ok' => false, 'msg' => '系统已安装，如需重装请先删除 data/install.lock'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $cfg   = read_form_cfg();
        $err   = validate_cfg($cfg);
        if ($err) {
            echo json_encode(['ok' => false, 'msg' => $err], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $adminUser = trim((string)($_POST['admin_user'] ?? 'admin'));
        $adminPass = (string)($_POST['admin_pass'] ?? '');
        if ($adminUser === '' || !preg_match('/^[A-Za-z0-9_]{3,32}$/', $adminUser)) {
            echo json_encode(['ok' => false, 'msg' => '管理员用户名需为 3-32 位字母/数字/下划线'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        // M2：密码留空 = 自动生成随机强密码（16 位 hex，含字母和数字，安装完成后一次性展示）；
        //     手动填写则要求 8-32 位且同时含字母与数字，杜绝 admin123 弱口令上线
        $autoGen = ($adminPass === '');
        if ($autoGen) {
            $adminPass = bin2hex(random_bytes(8));
        } elseif (strlen($adminPass) < 8 || strlen($adminPass) > 32) {
            echo json_encode(['ok' => false, 'msg' => '管理员密码长度需为 8-32 位（也可留空自动生成）'], JSON_UNESCAPED_UNICODE);
            exit;
        } elseif (!preg_match('/[A-Za-z]/', $adminPass) || !preg_match('/[0-9]/', $adminPass)) {
            echo json_encode(['ok' => false, 'msg' => '管理员密码必须同时包含字母和数字'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $log = [];
        try {
            // 1) 连库（必要时建库）
            $dsn = sprintf('mysql:host=%s;port=%d;charset=%s', $cfg['host'], $cfg['port'], $cfg['charset']);
            $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 8,
            ]);
            $pdo->exec("SET NAMES " . $cfg['charset']);
            $ex = $pdo->prepare("SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = ?");
            $ex->execute([$cfg['dbname']]);
            if (intval($ex->fetchColumn()) === 0) {
                $pdo->exec('CREATE DATABASE `' . str_replace('`', '', $cfg['dbname']) . '` '
                    . 'DEFAULT CHARACTER SET ' . preg_replace('/[^a-z0-9_]/i', '', $cfg['charset']));
                $log[] = "已创建数据库 {$cfg['dbname']}";
            }
            $pdo->exec('USE `' . str_replace('`', '', $cfg['dbname']) . '`');

            // 2) 执行 schema（带表前缀）
            if (!is_file($schema)) {
                throw new RuntimeException('缺少 data/schema.sql');
            }
            $sql = preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents($schema));
            $sql = Database::applyPrefixFor($sql, $cfg['prefix']);
            $n = 0;
            foreach (explode(';', $sql) as $stmt) {
                $clean = [];
                foreach (preg_split('/\r?\n/', $stmt) as $line) {
                    if (strpos(ltrim($line), '--') === 0) {
                        continue;
                    }
                    $clean[] = $line;
                }
                $stmt = trim(implode("\n", $clean));
                if ($stmt === '') {
                    continue;
                }
                try {
                    $pdo->exec($stmt);
                    $n++;
                } catch (PDOException $e) {
                    // MySQL 5.7 无 CREATE INDEX IF NOT EXISTS，重复执行报 Duplicate key name
                    $m = $e->getMessage();
                    if (strpos($m, 'Duplicate key name') === false && strpos($m, 'already exists') === false) {
                        throw $e;
                    }
                }
            }
            $log[] = "已执行 {$n} 条建表/初始化语句";

            // 2.5) 生成随机 UC 通信密钥：避免种子里的默认密钥直接上线
            //      （默认密钥一旦泄露，攻击者可伪造 UCenter 通知执行删用户/强制下线）
            $randKey = bin2hex(random_bytes(16));
            $st = $pdo->prepare(
                "UPDATE `" . str_replace('`', '', $cfg['prefix']) . "settings` SET s_value=?, updated_at=? WHERE s_key='uc_key'"
            );
            $st->execute([json_encode($randKey), time()]);
            $log[] = '已生成随机 UC 通信密钥（' . strlen($randKey) . ' 位，如需对接 UCenter 请同步到 UCenter 端）';

            // 3) 创建管理员（DML 用事务保证原子性）
            $admTable = '`' . $cfg['prefix'] . 'admins`';
            $pdo->beginTransaction();
            try {
                $has = intval($pdo->query("SELECT COUNT(*) FROM $admTable")->fetchColumn());
                if ($has === 0) {
                    $st = $pdo->prepare(
                        "INSERT INTO $admTable(username,password_hash,realname,role,permissions,status,created_at)
                         VALUES(?,?,?,?,?,1,?)"
                    );
                    $st->execute([
                        $adminUser,
                        password_hash($adminPass, PASSWORD_DEFAULT),
                        '超级管理员',
                        'super_admin',
                        json_encode(['*']),
                        time(),
                    ]);
                    $log[] = "已创建管理员 {$adminUser}";
                    $log[] = $autoGen
                        ? '管理员账号 ' . $adminUser . ' / 随机密码 ' . $adminPass . '（仅此一次展示，请立即复制保存）'
                        : '管理员账号 ' . $adminUser . ' / 密码已按输入设置（登录后请尽快在后台修改）';
                } else {
                    $log[] = '管理员表已有数据，跳过创建';
                }
                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }

            // 4) 写入凭据文件
            $php = "<?php\n"
                . "// 数据库连接凭据（由 install.php 生成；不入版本库，权限建议 600）\n"
                . "// 生成时间：" . date('Y-m-d H:i:s') . "\n"
                . "return [\n"
                . "  'host'    => " . var_export($cfg['host'], true) . ",\n"
                . "  'port'    => " . intval($cfg['port']) . ",\n"
                . "  'dbname'  => " . var_export($cfg['dbname'], true) . ",\n"
                . "  'user'    => " . var_export($cfg['user'], true) . ",\n"
                . "  'pass'    => " . var_export($cfg['pass'], true) . ",\n"
                . "  'charset' => " . var_export($cfg['charset'], true) . ",\n"
                . "  'prefix'  => " . var_export($cfg['prefix'], true) . ",\n"
                . "];\n";
            if (@file_put_contents($credF, $php) === false) {
                throw new RuntimeException('无法写入 data/db_cred.php，请检查目录写权限');
            }
            @chmod($credF, 0600);
            $log[] = '已写入 data/db_cred.php';

            // 5) 安装锁
            @file_put_contents($lockF, date('Y-m-d H:i:s') . "  installed\n");
            $log[] = '已写入 data/install.lock';

            $_SESSION['install_log'] = $log;
            echo json_encode(['ok' => true, 'msg' => '安装完成', 'log' => $log], JSON_UNESCAPED_UNICODE);
            exit;
        } catch (Exception $e) {
            echo json_encode(['ok' => false, 'msg' => '安装失败：' . $e->getMessage(), 'log' => $log], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    // ---- 删除安装文件 ----
    if ($act === 'remove') {
        if (!file_exists($lockF)) {
            echo json_encode(['ok' => false, 'msg' => '未完成安装，不能删除'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $ok = @unlink(__FILE__);
        echo json_encode(['ok' => $ok, 'msg' => $ok ? '已删除 install.php' : '删除失败，请手动删除 public/install.php'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['ok' => false, 'msg' => '未知操作'], JSON_UNESCAPED_UNICODE);
    exit;
}

// =============================================================
//  辅助函数
// =============================================================
function read_form_cfg()
{
    $cfg = [
        'host'    => trim((string)($_POST['db_host'] ?? '127.0.0.1')),
        'port'    => intval($_POST['db_port'] ?? 3306),
        'dbname'  => trim((string)($_POST['db_name'] ?? '')),
        'user'    => trim((string)($_POST['db_user'] ?? '')),
        'pass'    => (string)($_POST['db_pass'] ?? ''),
        'charset' => trim((string)($_POST['db_charset'] ?? 'utf8mb4')) ?: 'utf8mb4',
        'prefix'  => trim((string)($_POST['db_prefix'] ?? '')),
    ];
    // 密码留空 = 沿用已保存的凭据（表单提示如此，必须兑现，否则会把空密码写进凭据文件）
    if ($cfg['pass'] === '') {
        $oldFile = dirname(__DIR__) . '/data/db_cred.php';
        if (is_file($oldFile)) {
            $old = @include $oldFile;
            if (is_array($old)
                && ($old['dbname'] ?? '') === $cfg['dbname']
                && ($old['user'] ?? '') === $cfg['user']) {
                $cfg['pass'] = (string)($old['pass'] ?? '');
            }
        }
    }
    return $cfg;
}

function validate_cfg($cfg)
{
    if ($cfg['dbname'] === '') {
        return '请填写数据库名';
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/', $cfg['dbname'])) {
        return '数据库名只能包含字母、数字、下划线';
    }
    if ($cfg['user'] === '') {
        return '请填写数据库用户名';
    }
    if ($cfg['prefix'] !== '' && !preg_match('/^[A-Za-z0-9_]+$/', $cfg['prefix'])) {
        return '表前缀只能包含字母、数字、下划线';
    }
    if ($cfg['port'] < 1 || $cfg['port'] > 65535) {
        return '端口号不合法';
    }
    return null;
}

/** 读取已有凭据用于表单预填（密码不回显由前端留空处理） */
$existsCred = [];
if (is_file($credF)) {
    $tmp = @include $credF;
    if (is_array($tmp)) {
        $existsCred = $tmp;
    }
}
$installed = file_exists($lockF);
// 完成页展示安装日志
$doneLog = isset($_SESSION['install_log']) && is_array($_SESSION['install_log']) ? $_SESSION['install_log'] : [];

// 环境检查项
$env = [
    ['PHP 版本 ≥ 7.0', version_compare(PHP_VERSION, '7.0.0', '>='), PHP_VERSION],
    ['PDO MySQL 扩展', extension_loaded('pdo_mysql'), extension_loaded('pdo_mysql') ? '已加载' : '未加载'],
    ['data 目录可写', is_writable($ROOT . '/data'), is_writable($ROOT . '/data') ? '可写' : '不可写'],
    ['config 目录可写', is_writable($ROOT . '/config'), is_writable($ROOT . '/config') ? '可写' : '不可写'],
    ['schema.sql 存在', is_file($schema), is_file($schema) ? '存在' : '缺失'],
];
$envOk = true;
foreach ($env as $x) {
    if (!$x[1]) {
        $envOk = false;
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>安装向导 · 统一身份认证中心</title>
<link rel="stylesheet" href="assets/app.css">
<style>
body{background:#f5f6fa}
.install{max-width:760px;margin:36px auto;padding:0 16px}
.stepbar{display:flex;gap:8px;margin-bottom:18px}
.stepbar .s{flex:1;text-align:center;padding:8px 0;border-radius:6px;background:#e8eaf0;color:#7b8194;font-size:13px}
.stepbar .s.on{background:#5b6bf5;color:#fff}
</style>
</head>
<body>
<div class="install">
  <h2 style="margin:0 0 4px">统一身份认证中心 · 安装向导</h2>
  <div class="faint" style="font-size:13px;margin-bottom:18px">存储引擎：MySQL &nbsp;|&nbsp; 版本 <?= e(UIC_VERSION) ?></div>

  <div class="stepbar">
    <div class="s <?= $step === '1' ? 'on' : '' ?>">1 环境检查</div>
    <div class="s <?= $step === '2' ? 'on' : '' ?>">2 数据库配置</div>
    <div class="s <?= $step === '3' ? 'on' : '' ?>">3 完成</div>
  </div>

<?php if ($installed): ?>
  <div class="card">
    <div class="card-h">系统已安装</div>
    <div class="card-b">
      <div class="empty">检测到 <span class="mono">data/install.lock</span>，安装已被锁定。<br>
        如需重新安装，请先删除该文件与 <span class="mono">data/db_cred.php</span>。</div>
      <div class="row" style="margin-top:12px">
        <a class="btn btn-p" href="index.php">进入前台</a>
        <a class="btn" href="admin.php">进入后台</a>
        <button class="btn" type="button" onclick="INST.remove()">删除 install.php</button>
      </div>
      <pre class="code" id="out" style="margin-top:12px"></pre>
    </div>
  </div>
<?php elseif ($step === '1'): ?>
  <div class="card">
    <div class="card-h">环境检查</div>
    <div class="card-b">
      <table class="table">
        <thead><tr><th>检查项</th><th>状态</th><th>说明</th></tr></thead>
        <tbody>
        <?php foreach ($env as $x): ?>
          <tr>
            <td><?= e($x[0]) ?></td>
            <td><span class="badge badge-<?= $x[1] ? 'ok' : 'err' ?>"><?= $x[1] ? '通过' : '不通过' ?></span></td>
            <td class="faint"><?= e($x[2]) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <div class="row" style="margin-top:14px">
        <?php if ($envOk): ?>
          <a class="btn btn-p" href="install.php?step=2">下一步：配置数据库</a>
        <?php else: ?>
          <button class="btn" disabled>请先修复未通过的检查项</button>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php elseif ($step === '2'): ?>
  <div class="card">
    <div class="card-h">数据库配置</div>
    <div class="card-b">
      <form id="dbForm">
        <div class="grid g2">
          <div class="field" style="margin-bottom:10px">
            <label class="label" for="db_host">数据库主机</label>
            <input class="input" id="db_host" value="<?= e($existsCred['host'] ?? '127.0.0.1') ?>" placeholder="127.0.0.1">
          </div>
          <div class="field" style="margin-bottom:10px">
            <label class="label" for="db_port">端口</label>
            <input class="input" id="db_port" value="<?= e((string)($existsCred['port'] ?? 3306)) ?>">
          </div>
          <div class="field" style="margin-bottom:10px">
            <label class="label" for="db_name">数据库名</label>
            <input class="input" id="db_name" value="<?= e($existsCred['dbname'] ?? 'uic_auth') ?>" placeholder="uic_auth">
            <div class="hint">不存在时会自动尝试创建（需 CREATE 权限）。</div>
          </div>
          <div class="field" style="margin-bottom:10px">
            <label class="label" for="db_prefix">表前缀</label>
            <input class="input" id="db_prefix" value="<?= e($existsCred['prefix'] ?? '') ?>" placeholder="留空不加前缀，如 uic_">
          </div>
          <div class="field" style="margin-bottom:10px">
            <label class="label" for="db_user">用户名</label>
            <input class="input" id="db_user" value="<?= e($existsCred['user'] ?? 'root') ?>" placeholder="root">
          </div>
          <div class="field" style="margin-bottom:10px">
            <label class="label" for="db_pass">密码</label>
            <input class="input" id="db_pass" type="password" placeholder="留空表示不修改已有凭据">
          </div>
          <div class="field" style="margin-bottom:10px">
            <label class="label" for="db_charset">字符集</label>
            <input class="input" id="db_charset" value="<?= e($existsCred['charset'] ?? 'utf8mb4') ?>">
          </div>
        </div>
        <div class="divider"></div>
        <div class="grid g2">
          <div class="field" style="margin-bottom:10px">
            <label class="label" for="admin_user">管理员账号</label>
            <input class="input" id="admin_user" value="admin">
          </div>
          <div class="field" style="margin-bottom:10px">
            <label class="label" for="admin_pass">管理员密码</label>
            <input class="input" id="admin_pass" type="password" placeholder="留空自动生成随机强密码" autocomplete="new-password">
            <div class="hint">8-32 位且同时含字母和数字；留空则自动生成随机密码，安装完成后一次性展示。</div>
          </div>
        </div>
        <div class="row" style="margin-top:12px">
          <button class="btn" type="button" onclick="INST.test()">测试连接</button>
          <button class="btn btn-p" type="submit">开始安装</button>
          <a class="btn" href="install.php?step=1">上一步</a>
        </div>
      </form>
      <div class="divider"></div>
      <pre class="code" id="out">1) 先点「测试连接」确认数据库可用，再点「开始安装」。</pre>
    </div>
  </div>
<?php else: ?>
  <div class="card">
    <div class="card-h">安装完成</div>
    <div class="card-b">
      <pre class="code" id="out"><?= $doneLog ? e('[安装完成]' . "\n" . implode("\n", $doneLog)) : '尚未执行安装。' ?></pre>
      <div class="row" style="margin-top:12px">
        <a class="btn btn-p" href="index.php">进入前台</a>
        <a class="btn" href="admin.php">进入后台</a>
        <button class="btn" type="button" onclick="INST.remove()">删除 install.php</button>
      </div>
    </div>
  </div>
<?php endif; ?>

  <div class="faint" style="font-size:12.5px;margin-top:16px;text-align:center">
    安装完成后请务必删除 <span class="mono">public/install.php</span>
  </div>
</div>

<script>
var INST = {
  token: '<?= e($installToken) ?>',
  post: function(data){
    var f = new FormData();
    for (var k in data) f.append(k, data[k]);
    f.append('install_token', INST.token);
    return fetch('install.php', {method:'POST', body:f}).then(function(r){ return r.json(); });
  },
  out: function(t){ document.getElementById('out').textContent = t; },
  cfg: function(){
    return {
      db_host: document.getElementById('db_host').value.trim(),
      db_port: document.getElementById('db_port').value.trim(),
      db_name: document.getElementById('db_name').value.trim(),
      db_prefix: document.getElementById('db_prefix').value.trim(),
      db_user: document.getElementById('db_user').value.trim(),
      db_pass: document.getElementById('db_pass').value,
      db_charset: document.getElementById('db_charset').value.trim(),
      admin_user: document.getElementById('admin_user').value.trim(),
      admin_pass: document.getElementById('admin_pass').value
    };
  },
  test: function(){
    var c = this.cfg(); c.ajax = 'test';
    this.out('正在测试连接…');
    this.post(c).then(function(r){
      INST.out((r.ok ? '[成功] ' : '[失败] ') + r.msg);
    });
  },
  install: function(){
    var c = this.cfg(); c.ajax = 'install';
    this.out('正在安装，请稍候…');
    this.post(c).then(function(r){
      var t = (r.ok ? '[成功] ' : '[失败] ') + r.msg;
      if (r.log && r.log.length) t += '\n' + r.log.join('\n');
      INST.out(t);
      if (r.ok) setTimeout(function(){ location.href='install.php?step=3'; }, 800);
    });
  },
  remove: function(){
    if (!confirm('确定删除 install.php？删除后将无法再次进入安装向导。')) return;
    this.post({ajax:'remove'}).then(function(r){
      alert(r.msg);
      if (r.ok) location.href = 'index.php';
    });
  }
};
var f = document.getElementById('dbForm');
if (f) f.addEventListener('submit', function(ev){ ev.preventDefault(); INST.install(); });
</script>
</body>
</html>
