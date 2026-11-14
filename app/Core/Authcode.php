<?php
/**
 * UCenter 通信密钥算法 (Discuz/UCenter authcode)
 * 与 uc_client/lib/common.inc.php 完全一致
 */
class Authcode
{
    public static function encode($string, $key, $expiry = 0)
    {
        return self::run($string, 'ENCODE', $key, $expiry);
    }

    public static function decode($string, $key)
    {
        return self::run($string, 'DECODE', $key);
    }

    public static function run($string, $operation = 'DECODE', $key = '', $expiry = 0)
    {
        $ckey_length = 4;
        $key  = md5($key);
        $keya = md5(substr($key, 0, 16));
        $keyb = md5(substr($key, 16, 16));
        $keyc = $ckey_length
            ? ($operation === 'DECODE' ? substr($string, 0, $ckey_length) : substr(md5(microtime()), -$ckey_length))
            : '';

        $cryptkey   = $keya . md5($keya . $keyc);
        $key_length = strlen($cryptkey);

        if ($operation === 'DECODE') {
            $string = base64_decode(str_replace(array('-_'), array('+/'), substr($string, $ckey_length)));
        } else {
            $string = sprintf('%010d', $expiry ? $expiry + time() : 0)
                    . substr(md5($string . $keyb), 0, 16) . $string;
        }
        $string_length = strlen($string);

        $result = '';
        $box    = range(0, 255);
        $rndkey = array();
        for ($i = 0; $i <= 255; $i++) {
            $rndkey[$i] = ord($cryptkey[$i % $key_length]);
        }
        for ($j = $i = 0; $i < 256; $i++) {
            $j       = ($j + $box[$i] + $rndkey[$i]) % 256;
            $tmp     = $box[$i];
            $box[$i] = $box[$j];
            $box[$j] = $tmp;
        }
        for ($a = $j = $i = 0; $i < $string_length; $i++) {
            $a       = ($a + 1) % 256;
            $j       = ($j + $box[$a]) % 256;
            $tmp     = $box[$a];
            $box[$a] = $box[$j];
            $box[$j] = $tmp;
            $result .= chr(ord($string[$i]) ^ ($box[($box[$a] + $box[$j]) % 256]));
        }

        if ($operation === 'DECODE') {
            // 密钥不匹配时 $result 是乱码，前 10 位并非数字。
            // 原写法直接做减法，PHP 8 会抛 TypeError（PHP 7 仅 warning），
            // 因此这里先做数字校验，密钥错误时安全返回空串。
            $expiry = substr($result, 0, 10);
            $sign   = substr($result, 10, 16);
            $validExpiry = ($expiry !== '' && ctype_digit($expiry))
                && (intval($expiry) === 0 || intval($expiry) - time() > 0);
            if ($validExpiry && hash_equals(substr(md5(substr($result, 26) . $keyb), 0, 16), $sign)) {
                return substr($result, 26);
            }
            return '';
        }
        return $keyc . str_replace('=', '', base64_encode($result));
    }
}
