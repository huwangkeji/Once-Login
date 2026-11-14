<?php
/**
 * =============================================================
 *  UCenter 通知接收端（应用侧标准接口 api/uc.php）
 *
 *  UCenter 后台「基本设置 → 通知设置」里的每个开关，开启后
 *  UCenter 都会主动请求本地址推送通知：
 *
 *      GET/POST  {应用URL}/api/uc.php?code={authcode 密文}
 *
 *  请在 UCenter「应用管理」中把该应用的「接口文件名称」设为 api/uc.php
 *  （或留空使用默认值），并把应用 URL 指向本站根地址。
 *
 *  返回约定：绝大多数动作返回 1 表示成功；部分动作需返回数据
 *  （如 getcreditsettings 返回 serialize 串）。任何其他输出都会让
 *  UCenter 侧显示「通知失败」。
 * =============================================================
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

// 通知接口不涉及会话，禁用缓存并输出纯文本
@header('Content-Type: text/plain; charset=utf-8');
@header('Cache-Control: no-store, no-cache, must-revalidate');
@header('Pragma: no-cache');

// 通知不依赖 cookie，及时释放会话锁，避免与并发请求互相阻塞
if (session_status() === PHP_SESSION_ACTIVE) {
    @session_write_close();
}

try {
    $result = NotifyHandler::receive();
    echo $result['body'];
} catch (Exception $e) {
    // 兜底：绝不能因为异常让 UCenter 收到非 1 的响应
    Logger::error('ucenter', 'UCenter 通知处理异常: ' . $e->getMessage(), ['target' => 'api/uc.php']);
    echo '1';
} catch (Throwable $e) {
    echo '1';
}
