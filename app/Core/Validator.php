<?php
/**
 * 输入校验
 */
class Validator
{
    public static $errors = [];

    public static function fail($msg)
    {
        self::$errors[] = $msg;
        return false;
    }

    public static function messages()
    {
        return self::$errors;
    }

    public static function first()
    {
        return self::$errors ? self::$errors[0] : '';
    }

    public static function reset()
    {
        self::$errors = [];
    }

    public static function username($v)
    {
        $len = mb_strlen($v, 'UTF-8');
        if ($len < 3 || $len > 15) {
            return self::fail('用户名长度需为 3-15 个字符');
        }
        if (!preg_match('/^[\x{4e00}-\x{9fa5}a-zA-Z0-9_\-]+$/u', $v)) {
            return self::fail('用户名只能包含中文、字母、数字、下划线和短横线');
        }
        if (preg_match('/^[0-9]+$/', $v)) {
            return self::fail('用户名不能为纯数字');
        }
        return true;
    }

    public static function password($v, $min = null, $max = null)
    {
        $min = $min ?: Config::get('pwd_min_length', 6);
        $max = $max ?: Config::get('pwd_max_length', 32);
        $len = strlen($v);
        if ($len < $min || $len > $max) {
            return self::fail("密码长度需为 {$min}-{$max} 位");
        }
        if (ctype_space($v) || strpos($v, ' ') !== false) {
            return self::fail('密码不能包含空格');
        }
        return true;
    }

    public static function email($v)
    {
        if (!filter_var($v, FILTER_VALIDATE_EMAIL)) {
            return self::fail('邮箱格式不正确');
        }
        return true;
    }

    public static function mobile($v)
    {
        if (!preg_match('/^1[3-9]\d{9}$/', $v)) {
            return self::fail('手机号格式不正确');
        }
        return true;
    }

    public static function required($v, $label)
    {
        if ($v === null || $v === '') {
            return self::fail($label . '不能为空');
        }
        return true;
    }

    /** 判定账号类型：uid / uid / email / mobile */
    public static function accountType($account)
    {
        if (Validator::email($account)) { Validator::reset(); return 'email'; }
        Validator::reset();
        if (Validator::mobile($account)) { Validator::reset(); return 'mobile'; }
        Validator::reset();
        return 'username';
    }
}
