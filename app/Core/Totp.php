<?php
/**
 * TOTP 双因素认证（RFC 6238 / RFC 4226 HOTP）
 *
 * 纯 PHP 实现，零外部依赖（仅用 hash_hmac / random_bytes）。
 * 约定：
 *   - Base32 Secret 无 padding，20 字节熵（160 bit）
 *   - 30 秒时间步长、6 位验证码、允许 ±1 步时钟偏移
 *   - 配合 settings 表存储（admin_totp_secret_{id} 等扁平键），零表结构变更
 */
class Totp
{
    const STEP    = 30;   // 时间步长（秒）
    const DIGITS  = 6;    // 验证码位数
    const WINDOW  = 1;    // 允许 ±1 步时钟偏移

    /** 生成随机 Base32 Secret（不含填充符 '='） */
    public static function generateSecret()
    {
        return self::base32Encode(random_bytes(20));
    }

    /** 生成 otpauth:// URI（Google Authenticator / 其他认证器均支持） */
    public static function uri($secret, $label)
    {
        return 'otpauth://totp/' . rawurlencode($label)
            . '?secret=' . rawurlencode(self::normalizeSecret($secret))
            . '&issuer=' . rawurlencode('UICenter')
            . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::STEP;
    }

    /**
     * 校验一次性验证码
     * @param string $secret Base32 Secret
     * @param string $code   用户输入的 6 位验证码
     * @param int|null $time 测试用时间戳；默认当前时间
     */
    public static function verify($secret, $code, $time = null)
    {
        $secret = self::normalizeSecret($secret);
        $code   = preg_replace('/\s+/', '', (string)$code);
        if ($secret === '' || !preg_match('/^\d{' . self::DIGITS . '}$/', $code)) {
            return false;
        }
        $counter = intdiv($time === null ? time() : intval($time), self::STEP);
        for ($i = -self::WINDOW; $i <= self::WINDOW; $i++) {
            if (hash_equals(self::hotp($secret, $counter + $i), $code)) {
                return true;
            }
        }
        return false;
    }

    /** HOTP 一次性密码（RFC 4226：HMAC-SHA1 动态截断） */
    public static function hotp($secret, $counter)
    {
        $counter = intval($counter);
        $bin     = pack('NN', ($counter >> 32) & 0xffffffff, $counter & 0xffffffff); // 8 字节大端
        $key     = self::base32Decode($secret);
        $hash    = hash_hmac('sha1', $bin, $key, true);
        $offset  = ord($hash[strlen($hash) - 1]) & 0x0f;
        $value   = ((ord($hash[$offset]) & 0x7f) << 24)
                 | ((ord($hash[$offset + 1]) & 0xff) << 16)
                 | ((ord($hash[$offset + 2]) & 0xff) << 8)
                 | (ord($hash[$offset + 3]) & 0xff);
        return str_pad((string)($value % 1000000), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /** 当前时间步进值（用于一次性码防重放记录） */
    public static function timecode($time = null)
    {
        return intdiv($time === null ? time() : intval($time), self::STEP);
    }

    /* ---------------- Base32 ---------------- */

    private static function normalizeSecret($s)
    {
        return strtoupper(preg_replace('/[^A-Za-z2-7]/', '', (string)$s));
    }

    private static function base32Encode($bin)
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $out = ''; $bits = 0; $value = 0;
        for ($i = 0, $n = strlen($bin); $i < $n; $i++) {
            $value = ($value << 8) | ord($bin[$i]);
            $bits += 8;
            while ($bits >= 5) {
                $out .= $alphabet[($value >> ($bits - 5)) & 31];
                $bits -= 5;
            }
        }
        if ($bits > 0) {
            $out .= $alphabet[($value << (5 - $bits)) & 31];
        }
        return $out;
    }

    private static function base32Decode($s)
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $map = array_flip(str_split($alphabet));
        $s   = self::normalizeSecret($s);
        $out = ''; $bits = 0; $value = 0;
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            if (!isset($map[$s[$i]])) {
                continue;
            }
            $value = ($value << 5) | $map[$s[$i]];
            $bits  += 5;
            if ($bits >= 8) {
                $out .= chr(($value >> ($bits - 8)) & 0xff);
                $bits -= 8;
            }
        }
        return $out;
    }
}