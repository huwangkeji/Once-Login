<?php
/**
 * 【已废弃 / DEPRECATED】
 * 本文件已被 sdk/ 下的官方 SDK 取代（推荐使用 sdk/php/UicSdk.php 零依赖版，
 * 或 Composer 包 uic/sdk）。此处仅作历史参考，示例请见 sdk/examples/。
 *
 * 统一身份认证中心（UIC）PHP 接入 SDK
 * ----------------------------------------------------
 * 零依赖：仅用 curl / file_get_contents + JSON。
 * 适用场景：第三方站点（应用）通过 UIC 的 Open API 实现
 *          单点登录（SSO）、获取用户资料、单点退出。
 *
 * 部署：把本文件拷到你的项目，require 后 new UicClient(...) 即可。
 */

class UicClient
{
    /** @var string UIC 的 Open API 地址，例如 https://sso.example.com/api/index.php */
    private $apiBase;
    /** @var string 在 UIC 后台「应用管理」中创建应用后获得的 AppID */
    private $appId;
    /** @var string 应用密钥（仅在服务端使用，切勿下发到前端） */
    private $appSecret;
    /** @var string 用户登录/退出后 UIC 回跳到本站的地址 */
    private $redirectUri;

    /** 会话键名（用于在本站保存 UIC 颁发的 Token） */
    public $tokenSessionKey = 'uic_sso_token';

    public function __construct($apiBase, $appId, $appSecret, $redirectUri = '')
    {
        $this->apiBase    = rtrim($apiBase, '/');
        $this->appId      = $appId;
        $this->appSecret  = $appSecret;
        $this->redirectUri = $redirectUri;
    }

    /**
     * 1) 构造跳转到 UIC 登录的 URL（用户点击「使用统一账号登录」）
     */
    public function buildLoginUrl($redirect = '')
    {
        $redirect = $redirect ?: $this->redirectUri;
        $q = http_build_query([
            'c' => 'sso',
            'a' => 'authorize',
            'app_id' => $this->appId,
            'redirect' => $redirect,
        ]);
        return $this->apiBase . '/../index.php?' . $q;
        // 说明：UIC 登录入口为前台 index.php 的 sso/authorize，
        // 若你的 apiBase 已指向 /api/index.php，请改为 $this->apiBase . '/index.php?...'
    }

    /**
     * 2) UIC 回跳时携带 ticket，服务端用 ticket + secret 换取长期 Token
     * @return array ['ok'=>bool, 'token'=>string, 'message'=>string]
     */
    public function exchangeTicket($ticket)
    {
        $res = $this->call('sso', 'exchange', [
            'ticket'      => $ticket,
            'app_id'      => $this->appId,
            'app_secret'  => $this->appSecret,
        ]);
        if (isset($res['code']) && $res['code'] === 0 && !empty($res['data']['token'])) {
            return ['ok' => true, 'token' => $res['data']['token'], 'message' => ''];
        }
        return ['ok' => false, 'token' => '', 'message' => $res['message'] ?? '换取失败'];
    }

    /**
     * 3) 用 Token 获取用户资料
     * @return array|null
     */
    public function getUserInfo($token)
    {
        $res = $this->call('sso', 'userinfo', [
            'token'  => $token,
            'app_id' => $this->appId,
        ]);
        if (isset($res['code']) && $res['code'] === 0) {
            return $res['data'] ?? null;
        }
        return null;
    }

    /** 单点退出：吊销 Token */
    public function logout($token)
    {
        return $this->call('sso', 'logout', [
            'token'  => $token,
            'app_id' => $this->appId,
        ]);
    }

    /** 通信自检 */
    public function ping()
    {
        return $this->call('sso', 'test', [
            'app_id'     => $this->appId,
            'app_secret' => $this->appSecret,
        ]);
    }

    // ---------------- 内部 HTTP 调用 ----------------
    private function call($m, $a, $params = [])
    {
        $url = $this->apiBase . '?m=' . urlencode($m) . '&a=' . urlencode($a);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST       => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT    => 10,
            CURLOPT_HTTPHEADER => ['X-Requested-With: XMLHttpRequest'],
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
        $json = json_decode($body, true);
        return is_array($json) ? $json : ['code' => -1, 'message' => 'bad response: ' . substr((string)$body, 0, 200)];
    }
}
