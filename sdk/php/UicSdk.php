<?php
/**
 * 统一身份认证中心 · PHP SDK v1.0.0
 * 应用管理 + SSO 接入（基于 UCenter 驱动架构）
 *
 * 覆盖：初始化 / 管理侧鉴权(Cookie) / 应用侧鉴权(AppSecret) /
 *       应用列表 / 创建 / 更新 / 启停 / 删除 / 通信测试 /
 *       SSO 授权跳转 / Ticket 换 Token / Token 换用户信息 / 单点退出
 *
 * 使用示例见文件末尾 Demo 区。
 */

class UicSdkException extends RuntimeException
{
    public ?array $response;
    public int $httpCode;
    public function __construct(string $message, int $httpCode = 0, ?array $response = null, int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->httpCode = $httpCode;
        $this->response = $response;
    }
}

class UicSdk
{
    private string $baseURL;
    private int $timeout;
    private ?string $sessionId = null;
    private ?string $appId = null;
    private ?string $appSecret = null;
    /** @var callable|null */
    private $logger = null;

    /**
     * 初始化 SDK
     * @param string $baseURL  站点根 URL，如 http://127.0.0.5
     * @param int    $timeout  HTTP 超时秒数（默认 10）
     * @param callable|null $logger 日志回调 function(string $level, string $msg, array $ctx)
     */
    public function __construct(string $baseURL, int $timeout = 10, ?callable $logger = null)
    {
        $this->baseURL = rtrim($baseURL, '/');
        $this->timeout = max(1, $timeout);
        $this->logger  = $logger;
    }

    /** 设置管理侧 Cookie 会话（PHPSESSID） */
    public function setAdminSession(string $sessionId): self
    {
        $this->sessionId = $sessionId;
        return $this;
    }

    /** 设置应用侧凭据（AppID + AppSecret） */
    public function setAppCredentials(string $appId, string $appSecret): self
    {
        $this->appId     = $appId;
        $this->appSecret = $appSecret;
        return $this;
    }

    // ================= 管理接口：应用管理（需 admin_session）=================

    /** 获取应用列表（RBAC: view:apps） */
    public function appList(): array
    {
        return $this->request('GET', 'admin', 'app_list');
    }

    /**
     * 创建应用（RBAC: manage:apps）
     * @return array {id, app_id, app_secret}
     */
    public function appCreate(string $name, string $description = '', string $homeUrl = '', array $callbacks = [], bool $ssoEnabled = true): array
    {
        return $this->request('POST', 'admin', 'app_save', [
            'name'        => $name,
            'description' => $description,
            'home_url'    => $homeUrl,
            'callbacks'   => $callbacks,
            'sso_enabled' => $ssoEnabled ? 1 : 0,
        ]);
    }

    /** 更新应用配置（RBAC: manage:apps） */
    public function appUpdate(int $id, array $fields): array
    {
        return $this->request('POST', 'admin', 'app_save', array_merge(['id' => $id], $fields));
    }

    /** 重置 AppSecret（RBAC: manage:apps） @return array {app_secret} */
    public function appResetSecret(int $id): array
    {
        return $this->request('POST', 'admin', 'app_reset_secret', ['id' => $id]);
    }

    /** 启用 / 禁用应用（RBAC: manage:apps） */
    public function appToggle(int $id, bool $enabled): void
    {
        $this->request('POST', 'admin', 'app_toggle', ['id' => $id, 'status' => $enabled ? 1 : 0]);
    }

    /** 删除应用（RBAC: manage:apps） */
    public function appDelete(int $id): void
    {
        $this->request('POST', 'admin', 'app_delete', ['id' => $id]);
    }

    /** 通信测试（RBAC: view:apps） @return array {ok, message} */
    public function appTest(int $id): array
    {
        return $this->request('POST', 'admin', 'app_test', ['id' => $id]);
    }

    // ================= SSO 接口（应用侧，需 AppSecret）=================

    /** 构建 SSO 授权跳转 URL（用户浏览器端跳转） */
    public function authorizeUrl(string $appId, string $redirectUri): string
    {
        return $this->baseURL . '/api/index.php?m=sso&a=authorize&app_id=' . urlencode($appId) . '&redirect=' . urlencode($redirectUri);
    }

    /** ticket 换取 access_token（服务端对接） @return array {token, expires_in} */
    public function exchange(string $ticket, ?string $appId = null, ?string $appSecret = null): array
    {
        return $this->request('POST', 'sso', 'exchange', [
            'ticket'     => $ticket,
            'app_id'     => $appId     ?? $this->appId,
            'app_secret' => $appSecret ?? $this->appSecret,
        ]);
    }

    /** token 换取用户信息（服务端对接） @return array 用户信息 */
    public function userinfo(string $token, ?string $appId = null): array
    {
        return $this->request('POST', 'sso', 'userinfo', [
            'token'  => $token,
            'app_id' => $appId ?? $this->appId,
        ]);
    }

    /** 单点退出（通知认证中心销毁 token） */
    public function logout(string $token, ?string $appId = null): void
    {
        $this->request('POST', 'sso', 'logout', [
            'token'  => $token,
            'app_id' => $appId ?? $this->appId,
        ]);
    }

    /** 连通性测试（应用侧） @return array {ok, message} */
    public function testConnect(?string $appId = null, ?string $appSecret = null): array
    {
        return $this->request('POST', 'sso', 'test', [
            'app_id'     => $appId     ?? $this->appId,
            'app_secret' => $appSecret ?? $this->appSecret,
        ]);
    }

    // ================= HTTP 底层 =================

    private function request(string $method, string $m, string $a, array $params = []): array
    {
        $url = $this->baseURL . '/api/index.php?m=' . urlencode($m) . '&a=' . urlencode($a);
        $ch  = curl_init($url);

        $headers = [];
        if ($this->sessionId) {
            curl_setopt($ch, CURLOPT_COOKIE, 'PHPSESSID=' . $this->sessionId);
        }
        if ($this->appId && $this->appSecret && $m === 'sso') {
            $headers[] = 'Authorization: Basic ' . base64_encode($this->appId . ':' . $this->appSecret);
        }

        $payload = '';
        if ($method === 'POST') {
            $payload = http_build_query($params);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => max(1, (int)($this->timeout / 2)),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT      => 'UicSdk-PHP/1.0.0',
        ]);

        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        $this->log('debug', 'API 请求', ['url' => $url, 'method' => $method, 'http' => $httpCode, 'error' => $err ?: null]);

        if ($body === false || $err !== '') {
            throw new UicSdkException('网络请求失败: ' . $err, $httpCode);
        }

        $json = json_decode($body, true);
        if (!is_array($json) || !array_key_exists('code', $json)) {
            throw new UicSdkException('响应格式异常', $httpCode, ['raw' => mb_substr($body, 0, 500)]);
        }

        if ((int)$json['code'] !== 0) {
            $msg = $json['message'] ?? ($json['msg'] ?? '操作失败');
            throw new UicSdkException($msg, $httpCode, $json);
        }

        return $json['data'] ?? [];
    }

    private function log(string $level, string $msg, array $ctx = []): void
    {
        if ($this->logger) {
            ($this->logger)($level, $msg, $ctx);
        }
    }
}

/* ================================================================
   Demo：快速开始（可直接复制到项目中运行）
   ================================================================ */
if (PHP_SAPI === 'cli' && basename($argv[0] ?? '') === 'UicSdk.php') {
    echo "=== UicSdk PHP Demo ===\n";

    $sdk = new UicSdk('http://127.0.0.5', 10, function ($lvl, $msg, $ctx) {
        echo "[{$lvl}] {$msg}\n";
    });

    // 1) 应用侧：连通性测试
    echo "\n--- 应用侧连通性测试 ---\n";
    try {
        $sdk->setAppCredentials('your_app_id', 'your_app_secret');
        $r = $sdk->testConnect();
        echo "结果: " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
    } catch (UicSdkException $e) {
        echo "失败: " . $e->getMessage() . " (HTTP {$e->httpCode})\n";
    }

    // 2) 管理侧：获取应用列表（需先设置 admin 会话）
    echo "\n--- 管理侧应用列表（示例，需有效 PHPSESSID）---\n";
    // $sdk->setAdminSession('有效的_PHPSESSID');
    // print_r($sdk->appList());

    // 3) SSO 授权跳转 URL
    echo "\n--- SSO 授权跳转 URL ---\n";
    echo $sdk->authorizeUrl('your_app_id', 'http://your-app.com/callback') . "\n";

    echo "\nDemo 完成。\n";
}
