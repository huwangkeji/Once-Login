<?php
namespace UIC\SDK\Http;

use UIC\SDK\Config;
use UIC\SDK\Exception\ApiException;
use UIC\SDK\Exception\NetworkException;

/**
 * HTTP 请求封装（基于 cURL，支持指数退避重试）
 */
class Request
{
    private $config;
    private $lastResponse;
    private $lastHttpCode;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * 发送 HTTP 请求（自动按重试策略重试）
     *
     * @param string $method GET|POST
     * @param string $endpoint API 端点（如 ?m=user&a=login）
     * @param array  $params  请求参数
     * @param array  $headers 自定义请求头
     * @return array 解析后的 JSON 响应
     * @throws NetworkException|ApiException
     */
    public function send(string $method, string $endpoint, array $params = [], array $headers = []): array
    {
        $retryCfg = $this->config->get('retry', false);
        if (!$retryCfg) {
            return $this->sendOnce($method, $endpoint, $params, $headers);
        }
        if (is_array($retryCfg)) {
            $policy = new RetryPolicy(
                (int) ($retryCfg['max_retries'] ?? 3),
                (int) ($retryCfg['base_delay_ms'] ?? 100),
                (int) ($retryCfg['max_delay_ms'] ?? 5000)
            );
        } else {
            $policy = new RetryPolicy();
        }

        $attempt = 0;
        while (true) {
            try {
                return $this->sendOnce($method, $endpoint, $params, $headers);
            } catch (ApiException | NetworkException $e) {
                if ($attempt >= $policy->getMaxRetries() || !$policy->isRetryable($e)) {
                    throw $e;
                }
                usleep($policy->getDelayMs($attempt) * 1000);
                $this->log('重试 ' . ($attempt + 1) . '/' . $policy->getMaxRetries());
                $attempt++;
            }
        }
    }

    /**
     * 单次发送 HTTP 请求（不重试）
     *
     * @param string $method GET|POST
     * @param string $endpoint API 端点（如 ?m=user&a=login）
     * @param array  $params  请求参数
     * @param array  $headers 自定义请求头
     * @return array 解析后的 JSON 响应
     * @throws NetworkException|ApiException
     */
    public function sendOnce(string $method, string $endpoint, array $params = [], array $headers = []): array
    {
        $url = $this->config->getApiBaseUrl() . $endpoint;
        $ch = curl_init();

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->config->get('timeout', 30),
            CURLOPT_CONNECTTIMEOUT => $this->config->get('connect_timeout', 10),
            CURLOPT_SSL_VERIFYPEER => $this->config->get('ssl_verify', true),
            CURLOPT_SSL_VERIFYHOST => $this->config->get('ssl_verify', true) ? 2 : 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'UIC-SDK/1.0 PHP/' . PHP_VERSION,
            CURLOPT_HEADER         => true,
        ];

        $defaultHeaders = [
            'Accept: application/json',
            'X-App-Id: ' . $this->config->get('app_id', ''),
        ];

        if (strtoupper($method) === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = http_build_query($params);
            $defaultHeaders[] = 'Content-Type: application/x-www-form-urlencoded';
        } else {
            if (!empty($params)) {
                $url .= (strpos($url, '?') !== false ? '&' : '?') . http_build_query($params);
            }
        }

        $opts[CURLOPT_HTTPHEADER] = array_merge($defaultHeaders, $headers);
        $opts[CURLOPT_URL] = $url;

        curl_setopt_array($ch, $opts);

        $this->log('请求: ' . $method . ' ' . $url);
        if ($this->config->isDebug() && !empty($params)) {
            $this->log('参数: ' . json_encode($params, JSON_UNESCAPED_UNICODE));
        }

        $response = curl_exec($ch);
        $err = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($response === false) {
            throw new NetworkException('网络请求失败: ' . $err);
        }

        $this->lastHttpCode = $code;
        $headers = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        $this->lastResponse = $body;

        $this->log('响应 HTTP ' . $code);

        if ($code >= 500) {
            throw new NetworkException('服务器错误 HTTP ' . $code, $code);
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new ApiException('响应解析失败: ' . mb_substr($body, 0, 200), $code);
        }

        if (isset($data['code']) && $data['code'] !== 0 && $data['code'] !== 200) {
            throw new ApiException($data['message'] ?? '请求失败', (int) $data['code'], $data['data'] ?? null);
        }

        return $data;
    }

    public function getLastResponse(): string
    {
        return $this->lastResponse ?? '';
    }

    public function getLastHttpCode(): int
    {
        return $this->lastHttpCode ?? 0;
    }

    private function log(string $msg): void
    {
        if (!$this->config->isDebug()) return;
        $file = $this->config->get('log_file');
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
        if ($file) {
            file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
        } else {
            error_log('[UIC-SDK] ' . $msg);
        }
    }
}
