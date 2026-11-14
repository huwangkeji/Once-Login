<?php
namespace UIC\SDK\Tests;

use PHPUnit\Framework\TestCase;
use UIC\SDK\Config;

class ConfigTest extends TestCase
{
    public function testDefaultValues()
    {
        $config = new Config();
        $this->assertEquals('http://127.0.0.5', $config->get('base_url'));
        $this->assertEquals('v1', $config->get('api_version'));
        $this->assertEquals(30, $config->get('timeout'));
        $this->assertTrue($config->get('ssl_verify'));
        $this->assertFalse($config->get('debug'));
    }

    public function testCustomValues()
    {
        $config = new Config([
            'base_url' => 'https://auth.example.com',
            'timeout'  => 60,
            'debug'    => true,
        ]);
        $this->assertEquals('https://auth.example.com', $config->get('base_url'));
        $this->assertEquals(60, $config->get('timeout'));
        $this->assertTrue($config->isDebug());
    }

    public function testGetApiBaseUrl()
    {
        $config = new Config(['base_url' => 'http://uic.local']);
        $this->assertStringContainsString('uic.local/api/index.php', $config->getApiBaseUrl());
    }

    public function testGetSsoUrl()
    {
        $config = new Config(['base_url' => 'http://uic.local']);
        $this->assertStringContainsString('uic.local/index.php?c=sso&a=authorize', $config->getSsoUrl());
    }

    public function testSetAndAll()
    {
        $config = new Config();
        $config->set('custom_key', 'custom_value');
        $this->assertEquals('custom_value', $config->get('custom_key'));
        $this->assertArrayHasKey('custom_key', $config->all());
    }

    public function testTrailingSlashRemoval()
    {
        $config = new Config(['base_url' => 'http://uic.local/']);
        // 注意：http:// 本身含 //，此处应断言域名后无残留双斜杠
        $this->assertStringNotContainsString('uic.local//', $config->getApiBaseUrl());
    }
}
