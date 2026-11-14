<?php
namespace UIC\SDK\Http;

/**
 * 指数退避重试策略
 */
class RetryPolicy
{
    private $maxRetries;
    private $baseDelayMs;
    private $maxDelayMs;
    private $retryableStatuses;

    /**
     * @param int   $maxRetries       最大重试次数（默认 3）
     * @param int   $baseDelayMs      基础延迟毫秒（默认 100）
     * @param int   $maxDelayMs       最大延迟毫秒（默认 5000）
     * @param array $retryableStatuses 可重试的 HTTP 状态码或错误码
     */
    public function __construct(
        int $maxRetries = 3,
        int $baseDelayMs = 100,
        int $maxDelayMs = 5000,
        array $retryableStatuses = [408, 429, 500, 502, 503, 504]
    ) {
        $this->maxRetries = max(0, $maxRetries);
        $this->baseDelayMs = max(1, $baseDelayMs);
        $this->maxDelayMs = max($this->baseDelayMs, $maxDelayMs);
        $this->retryableStatuses = $retryableStatuses;
    }

    /**
     * 判断错误是否可重试
     */
    public function isRetryable(\Exception $e): bool
    {
        if ($e instanceof \UIC\SDK\Exception\NetworkException) {
            $code = $e->getCode();
            return in_array($code, $this->retryableStatuses, true) || $code === 0;
        }
        if ($e instanceof \UIC\SDK\Exception\ApiException) {
            return in_array($e->getCode(), $this->retryableStatuses, true);
        }
        return false;
    }

    /**
     * 计算第 $attempt 次重试的延迟（指数退避 + 随机抖动）
     */
    public function getDelayMs(int $attempt): int
    {
        $delay = $this->baseDelayMs * pow(2, $attempt);
        $delay = min($delay, $this->maxDelayMs);
        // 添加 +/- 25% 的随机抖动，避免惊群效应
        $jitter = (int) ($delay * 0.25);
        $delay += random_int(-$jitter, $jitter);
        return max(1, $delay);
    }

    public function getMaxRetries(): int
    {
        return $this->maxRetries;
    }
}
