<?php
namespace UIC\SDK\Tests;

use PHPUnit\Framework\TestCase;
use UIC\SDK\Utils\Signer;

class SignerTest extends TestCase
{
    public function testSignConsistency()
    {
        $params = ['name' => 'test', 'id' => 123];
        $secret = 'my_secret_key';
        $timestamp = '1695123456';

        $sign1 = Signer::sign($params, $secret, $timestamp);
        $sign2 = Signer::sign($params, $secret, $timestamp);

        $this->assertEquals($sign1, $sign2);
        $this->assertEquals(64, strlen($sign1)); // SHA-256 = 64 hex chars
    }

    public function testSignIgnoresEmptyValues()
    {
        $params1 = ['name' => 'test', 'empty' => ''];
        $params2 = ['name' => 'test'];
        $secret = 'key';
        $timestamp = '1234567890';

        $sign1 = Signer::sign($params1, $secret, $timestamp);
        $sign2 = Signer::sign($params2, $secret, $timestamp);

        $this->assertEquals($sign1, $sign2);
    }

    public function testSignIgnoresSignField()
    {
        $params1 = ['name' => 'test'];
        $params2 = ['name' => 'test', 'sign' => 'existing_sign'];
        $secret = 'key';
        $timestamp = '1234567890';

        $sign1 = Signer::sign($params1, $secret, $timestamp);
        $sign2 = Signer::sign($params2, $secret, $timestamp);

        $this->assertEquals($sign1, $sign2);
    }

    public function testSignDifferentParams()
    {
        $params1 = ['name' => 'test1'];
        $params2 = ['name' => 'test2'];
        $secret = 'key';
        $timestamp = '1234567890';

        $sign1 = Signer::sign($params1, $secret, $timestamp);
        $sign2 = Signer::sign($params2, $secret, $timestamp);

        $this->assertNotEquals($sign1, $sign2);
    }

    public function testSignDifferentSecrets()
    {
        $params = ['name' => 'test'];
        $timestamp = '1234567890';

        $sign1 = Signer::sign($params, 'secret1', $timestamp);
        $sign2 = Signer::sign($params, 'secret2', $timestamp);

        $this->assertNotEquals($sign1, $sign2);
    }

    public function testVerifySuccess()
    {
        $params = ['name' => 'test', 'id' => 123];
        $secret = 'my_secret';
        $timestamp = '1695123456';

        $sign = Signer::sign($params, $secret, $timestamp);
        $this->assertTrue(Signer::verify($params, $secret, $timestamp, $sign));
    }

    public function testVerifyFailure()
    {
        $params = ['name' => 'test'];
        $secret = 'my_secret';
        $timestamp = '1695123456';

        $this->assertFalse(Signer::verify($params, $secret, $timestamp, 'invalid_sign'));
    }

    public function testSignWithNestedArray()
    {
        $params = ['data' => ['id' => 1, 'name' => 'test']];
        $secret = 'key';
        $timestamp = '1234567890';

        $sign = Signer::sign($params, $secret, $timestamp);
        $this->assertEquals(64, strlen($sign));
    }
}
