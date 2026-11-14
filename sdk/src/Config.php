<?php
namespace UIC\SDK;

/**
 * SDK 全局配置管理
 */
class Config
{
    private static $defaults = [
        'base_url'      => 'http://127.0.0.5',
        'api_version'   => 'v1',
        'app_id'        => '',
        'app_secret'    => '',
        'timeout'       => 30,
        'connect_timeout'=> 10,
        'ssl_verify'    => true,
        'debug'         => false,
        'log_file'      => '',
        // 重试策略：false 关闭；数组 [max_retries, base_delay_ms, max_delay_ms]
        'retry'         => false,
    ];

    private $values;

    public function __construct(array $options = [])
    {
        $this->values = array_merge(self::$defaults, $options);
    }

    public function get(string $key, $default = null)
    {
        return $this->values[$key] ?? $default;
    }

    public function set(string $key, $value): self
    {
        $this->values[$key] = $value;
        return $this;
    }

    public function all(): array
    {
        return $this->values;
    }

    public function getApiBaseUrl(): string
    {
        return rtrim($this->get('base_url'), '/') . '/api/index.php';
    }

    public function getSsoUrl(): string
    {
        return rtrim($this->get('base_url'), '/') . '/index.php?c=sso&a=authorize';
    }

    public function isDebug(): bool
    {
        return (bool) $this->get('debug', false);
    }
}
