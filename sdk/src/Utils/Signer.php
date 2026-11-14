<?php
namespace UIC\SDK\Utils;

/**
 * 请求签名工具（HMAC-SHA256）
 */
class Signer
{
    /**
     * 生成请求签名
     *
     * @param array  $params    请求参数（不含 sign）
     * @param string $secret    AppSecret
     * @param string $timestamp 时间戳
     * @return string
     */
    public static function sign(array $params, string $secret, string $timestamp): string
    {
        // 1) 过滤空值与 sign 字段
        $data = array_filter($params, function ($v, $k) {
            return $v !== '' && $v !== null && $k !== 'sign';
        }, ARRAY_FILTER_USE_BOTH);

        // 2) 按 key 字典序排序
        ksort($data);

        // 3) 拼接成 key1=value1&key2=value2 字符串
        $pairs = [];
        foreach ($data as $k => $v) {
            $pairs[] = $k . '=' . (is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string) $v);
        }
        $baseString = implode('&', $pairs);

        // 4) 附加时间戳与密钥
        $payload = $baseString . '&timestamp=' . $timestamp . '&secret=' . $secret;

        return hash_hmac('sha256', $payload, $secret);
    }

    /**
     * 验证签名
     */
    public static function verify(array $params, string $secret, string $timestamp, string $sign): bool
    {
        return hash_equals(self::sign($params, $secret, $timestamp), $sign);
    }
}
