<?php
namespace UIC\SDK;

use UIC\SDK\Http\Request;
use UIC\SDK\Auth\TokenManager;
use UIC\SDK\Exception\ApiException;
use UIC\SDK\Exception\AuthException;

/**
 * 用户认证客户端（OAuth2.0 / SSO）
 */
class AuthClient
{
    private $http;
    private $config;
    private $tokenManager;

    public function __construct(Config $config, Request $http, TokenManager $tokenManager)
    {
        $this->config = $config;
        $this->http = $http;
        $this->tokenManager = $tokenManager;
    }

    /**
     * 获取 SSO 授权跳转 URL
     *
     * @param string $callback 回调地址
     * @param string $state    防 CSRF 状态码
     * @return string
     */
    public function getAuthorizeUrl(string $callback, string $state = ''): string
    {
        $params = [
            'app_id'   => $this->config->get('app_id'),
            'redirect' => $callback,
        ];
        if ($state !== '') {
            $params['state'] = $state;
        }
        return $this->config->getSsoUrl() . '&' . http_build_query($params);
    }

    /**
     * 用 ticket 换取 access_token（服务端对接）
     *
     * @param string $ticket
     * @return array {token, expires_in, refresh_token}
     * @throws ApiException
     */
    public function exchangeToken(string $ticket): array
    {
        $res = $this->http->send('POST', '?m=sso&a=exchange', [
            'ticket'     => $ticket,
            'app_id'     => $this->config->get('app_id'),
            'app_secret' => $this->config->get('app_secret'),
        ]);
        $data = $res['data'] ?? [];
        if (empty($data['token'])) {
            throw new AuthException('Token 换取失败：响应中未包含 token');
        }
        $this->tokenManager->setToken(
            $data['token'],
            $data['refresh_token'] ?? null,
            $data['expires_in'] ?? 7200
        );
        return $data;
    }

    /**
     * 使用 token 获取用户信息（服务端对接，无 Cookie 会话场景）
     *
     * @param string $token 不传则使用 TokenManager 中的当前 token
     * @return array 用户信息
     */
    public function getUserInfo(string $token = ''): array
    {
        $token = $token ?: $this->tokenManager->getToken();
        if (!$token) {
            throw new AuthException('缺少 access_token，请先完成认证');
        }
        $res = $this->http->send('POST', '?m=sso&a=userinfo', [
            'token'  => $token,
            'app_id' => $this->config->get('app_id'),
        ]);
        return $res['data'] ?? [];
    }

    /**
     * SSO 单点退出
     *
     * @param string $token
     * @return bool
     */
    public function logout(string $token = ''): bool
    {
        $token = $token ?: $this->tokenManager->getToken();
        if (!$token) {
            return false;
        }
        try {
            $this->http->send('POST', '?m=sso&a=logout', [
                'token'  => $token,
                'app_id' => $this->config->get('app_id'),
            ]);
        } catch (ApiException $e) {
            // 忽略退出时的接口异常
        }
        $this->tokenManager->clear();
        return true;
    }

    /**
     * 通信测试
     *
     * @return bool
     */
    public function testConnect(): bool
    {
        try {
            $res = $this->http->send('POST', '?m=sso&a=test', [
                'app_id'     => $this->config->get('app_id'),
                'app_secret' => $this->config->get('app_secret'),
            ]);
            return ($res['code'] ?? 1) === 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 获取当前已缓存的 token
     */
    public function getToken(): ?string
    {
        return $this->tokenManager->getToken();
    }

    /**
     * Token 是否已过期（预留，当前服务端 token 无固定过期时间，依赖 session）
     */
    public function isTokenExpired(): bool
    {
        return $this->tokenManager->isExpired();
    }
}
