<?php
namespace UIC\SDK;

/**
 * SDK 统一入口
 *
 * 使用示例:
 * ```php
 * use UIC\SDK\Client;
 * $client = new Client(['app_id'=>'xx','app_secret'=>'yy','base_url'=>'http://uic.local']);
 * $authUrl = $client->auth()->getAuthorizeUrl('http://app.local/callback');
 * $user = $client->user()->getInfo();
 * ```
 */
class Client
{
    /** @var Config */
    private $config;
    /** @var Http\Request */
    private $http;
    /** @var Auth\TokenManager */
    private $tokenManager;
    /** @var AuthClient|null */
    private $auth;
    /** @var UserClient|null */
    private $user;
    /** @var CaptchaClient|null */
    private $captcha;

    /**
     * @param array $options
     *   - base_url      API 根地址 (默认 http://127.0.0.5)
     *   - app_id        应用 ID（后台「应用管理」创建后获得）
     *   - app_secret    应用密钥
     *   - timeout       请求超时秒数（默认 30）
     *   - connect_timeout 连接超时秒数（默认 10）
     *   - ssl_verify    是否校验 SSL 证书（默认 true）
     *   - debug         是否开启调试日志（默认 false）
     *   - log_file      调试日志文件路径（默认输出到 error_log）
     *   - token_storage token 持久化文件路径（可选）
     */
    public function __construct(array $options = [])
    {
        $this->config = new Config($options);
        $this->http = new Http\Request($this->config);
        $this->tokenManager = new Auth\TokenManager($options['token_storage'] ?? '');
    }

    /** 认证/SSO 客户端 */
    public function auth(): AuthClient
    {
        if (!$this->auth) {
            $this->auth = new AuthClient($this->config, $this->http, $this->tokenManager);
        }
        return $this->auth;
    }

    /** 用户业务客户端（需会话 Cookie） */
    public function user(): UserClient
    {
        if (!$this->user) {
            $this->user = new UserClient($this->config, $this->http);
        }
        return $this->user;
    }

    /** 图形验证码客户端 */
    public function captcha(): CaptchaClient
    {
        if (!$this->captcha) {
            $this->captcha = new CaptchaClient($this->config, $this->http);
        }
        return $this->captcha;
    }

    /** 获取当前配置 */
    public function getConfig(): Config
    {
        return $this->config;
    }

    /** 设置配置项 */
    public function setConfig(string $key, $value): self
    {
        $this->config->set($key, $value);
        return $this;
    }
}
