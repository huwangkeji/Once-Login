<?php
namespace UIC\SDK\Tests;

use PHPUnit\Framework\TestCase;
use UIC\SDK\Http\RetryPolicy;
use UIC\SDK\Exception\ApiException;
use UIC\SDK\Exception\NetworkException;

class RetryPolicyTest extends TestCase
{
    public function testDefaults(): void
    {
        $p = new RetryPolicy();
        $this->assertSame(3, $p->getMaxRetries());
    }

    public function testCustomRetries(): void
    {
        $p = new RetryPolicy(5);
        $this->assertSame(5, $p->getMaxRetries());
        $p = new RetryPolicy(-2);
        $this->assertSame(0, $p->getMaxRetries());
    }

    public function testNetworkExceptionRetryable(): void
    {
        $p = new RetryPolicy();
        $e = new NetworkException('超时', 0);
        $this->assertTrue($p->isRetryable($e));
    }

    public function testServerErrorRetryable(): void
    {
        $p = new RetryPolicy();
        $e = new NetworkException('服务器错误', 503);
        $this->assertTrue($p->isRetryable($e));
        $e2 = new ApiException('限流', 429);
        $this->assertTrue($p->isRetryable($e2));
    }

    public function testBusinessErrorNotRetryable(): void
    {
        $p = new RetryPolicy();
        $e = new ApiException('参数错误', 1001);
        $this->assertFalse($p->isRetryable($e));
    }

    public function testCustomStatuses(): void
    {
        $p = new RetryPolicy(3, 100, 5000, [1001]);
        $e = new ApiException('业务可重试', 1001);
        $this->assertTrue($p->isRetryable($e));
    }

    public function testDelayGrowsExponentially(): void
    {
        $p = new RetryPolicy(3, 100, 5000);
        $d1 = $p->getDelayMs(0);
        $d2 = $p->getDelayMs(1);
        $d3 = $p->getDelayMs(2);
        // 基础延迟 100，抖动 +-25%
        $this->assertGreaterThanOrEqual(1, $d1);
        $this->assertLessThanOrEqual(125, $d1);
        $this->assertLessThanOrEqual(250, $d2);
        $this->assertLessThanOrEqual(500, $d3);
    }

    public function testDelayCappedAtMax(): void
    {
        $p = new RetryPolicy(10, 1000, 5000);
        for ($i = 0; $i < 8; $i++) {
            $this->assertLessThanOrEqual(6250, $p->getDelayMs($i));
        }
    }
}