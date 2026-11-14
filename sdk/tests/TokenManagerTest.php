<?php
namespace UIC\SDK\Tests;

use PHPUnit\Framework\TestCase;
use UIC\SDK\Auth\TokenManager;

class TokenManagerTest extends TestCase
{
    private $tempFile;

    protected function setUp(): void
    {
        $this->tempFile = sys_get_temp_dir() . '/uic_test_token_' . uniqid() . '.json';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
    }

    public function testSetAndGetToken()
    {
        $tm = new TokenManager($this->tempFile);
        $tm->setToken('test_token_123', 'refresh_456', 3600);

        $this->assertEquals('test_token_123', $tm->getToken());
        $this->assertEquals('refresh_456', $tm->getRefreshToken());
        $this->assertFalse($tm->isExpired());
    }

    public function testTokenExpiration()
    {
        $tm = new TokenManager($this->tempFile);
        $tm->setToken('token', 'refresh', 1); // 1秒过期，且 SDK 提前 60 秒判过期
        $this->assertTrue($tm->isExpired());
    }

    public function testTokenNotExpiredWithLongTtl()
    {
        $tm = new TokenManager($this->tempFile);
        $tm->setToken('token', 'refresh', 7200);

        $this->assertFalse($tm->isExpired());
    }

    public function testClear()
    {
        $tm = new TokenManager($this->tempFile);
        $tm->setToken('token', 'refresh', 3600);
        $tm->clear();

        $this->assertNull($tm->getToken());
        $this->assertNull($tm->getRefreshToken());
        $this->assertFalse(file_exists($this->tempFile));
    }

    public function testPersistence()
    {
        $tm1 = new TokenManager($this->tempFile);
        $tm1->setToken('persistent_token', 'persistent_refresh', 3600);

        // 模拟新实例加载持久化数据
        $tm2 = new TokenManager($this->tempFile);
        $this->assertEquals('persistent_token', $tm2->getToken());
        $this->assertEquals('persistent_refresh', $tm2->getRefreshToken());
    }

    public function testNoStoragePath()
    {
        $tm = new TokenManager();
        $tm->setToken('token', 'refresh', 3600);

        $this->assertEquals('token', $tm->getToken());
        $this->assertFalse($tm->isExpired());
    }

    public function testExpiredDetection()
    {
        $tm = new TokenManager($this->tempFile);
        $tm->setToken('token', 'refresh', 120); // 2分钟

        // 提前 60 秒检测，剩余时间 > 60 秒
        $this->assertFalse($tm->isExpired());
    }

    public function testEmptyToken()
    {
        $tm = new TokenManager($this->tempFile);
        $this->assertNull($tm->getToken());
        $this->assertNull($tm->getRefreshToken());
    }
}
