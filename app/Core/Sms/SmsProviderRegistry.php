<?php
/**
 * 短信服务商注册表（可插拔接入层）
 *
 * 新增服务商的唯一接入点：
 *   SmsProviderRegistry::register('myprovider', MySmsProvider::class);
 * 或在 defaults() 中增加一行。核心发送逻辑（SmsGateway）与业务层均无需改动。
 */
class SmsProviderRegistry
{
    /** 内置服务商（实现 SmsProviderInterface 的类名） */
    public static function defaults()
    {
        return [
            'tencent' => 'TencentSmsProvider',
            'smsbao'  => 'SmsBaoSmsProvider',
            'aliyun'  => 'AliyunSmsProvider',
            'huawei'  => 'HuaweiSmsProvider',
        ];
    }

    /** 运行时注册（插件/扩展用；重复注册按 key 覆盖） */
    public static function register($key, $class)
    {
        $key = strtolower(trim((string)$key));
        if ($key === '' || !class_exists($class)) {
            return false;
        }
        $all = self::all();
        $all[$key] = $class;
        self::$cache = $all;
        return true;
    }

    private static $cache = null;

    /** 全部已注册服务商：key => class */
    public static function all()
    {
        if (self::$cache === null) {
            self::$cache = self::defaults();
        }
        return self::$cache;
    }

    /** 取类名 */
    public static function get($key)
    {
        $all = self::all();
        return isset($all[$key]) ? $all[$key] : null;
    }

    /** 取实例 */
    public static function instance($key)
    {
        $cls = self::get($key);
        if (!$cls) {
            return null;
        }
        return new $cls();
    }

    /** 后台下拉选项：[key => 显示名]（含「未接入」） */
    public static function options()
    {
        $out = ['' => '未接入（调试模式）'];
        foreach (self::all() as $k => $cls) {
            $out[$k] = $cls::name();
        }
        return $out;
    }

    /** 各服务商的解析后配置（含 enabled / priority，密钥已掩码） */
    public static function configs()
    {
        $out = [];
        foreach (self::all() as $k => $cls) {
            $cfg = $cls::maskConfig();
            $cfg['name']       = $cls::name();
            $cfg['configured'] = $cls::isConfigured();
            $cfg['fields']     = $cls::fields();
            $out[$k] = $cfg;
        }
        return $out;
    }

    /**
     * 可用服务商列表：已启用 + 凭据完整，按 priority 升序（数字越小越优先）
     * @return array [ ['key'=>..,'priority'=>..,'instance'=>..] ]
     */
    public static function available()
    {
        $list = [];
        foreach (self::all() as $k => $cls) {
            $cfg = $cls::resolveConfig();
            if (empty($cfg['enabled'])) {
                continue;
            }
            if (!$cls::isConfigured()) {
                continue;
            }
            $list[] = [
                'key'      => $k,
                'name'     => $cls::name(),
                'priority' => intval($cfg['priority'] ?? 50),
                'instance' => new $cls(),
                'config'   => $cfg,
            ];
        }
        usort($list, function ($a, $b) {
            return $a['priority'] <=> $b['priority'];
        });
        return $list;
    }
}
