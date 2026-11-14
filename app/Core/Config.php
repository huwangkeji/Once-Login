<?php
/**
 * 配置管理：config/config.php 为默认值，MySQL settings 表覆盖。
 */
class Config
{
    private static $defaults = null;
    private static $cache = null;

    public static function defaults()
    {
        if (self::$defaults === null) {
            self::$defaults = require dirname(__DIR__, 2) . '/config/config.php';
        }
        return self::$defaults;
    }

    private static function load()
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        self::$cache = [];
        try {
            $db   = Database::getInstance();
            $rows = $db->query("SELECT s_key, s_value FROM settings");
            foreach ($rows as $r) {
                $v = json_decode($r['s_value'], true);
                self::$cache[$r['s_key']] = ($v === null) ? $r['s_value'] : $v;
            }
        } catch (Exception $e) {
            // 表未就绪时静默降级到文件配置
        }
        return self::$cache;
    }

    /** 嵌套配置键 → settings 扁平键 映射（settings 表一律以扁平键存储） */
    const FLAT_MAP = [
        'ucenter.enabled'           => 'uc_enabled',
        'ucenter.driver'            => 'uc_driver',
        'ucenter.url'               => 'uc_url',
        'ucenter.ip'                => 'uc_ip',
        'ucenter.key'               => 'uc_key',
        'ucenter.appid'             => 'uc_appid',
        'ucenter.connect'           => 'uc_connect',
        'ucenter.charset'           => 'uc_charset',
        'ucenter.timeout'           => 'uc_timeout',
        'sso.session_ttl'           => 'session_ttl',
        'sso.remember_ttl'          => 'remember_ttl',
        'sso.ticket_ttl'            => 'ticket_ttl',
        'sso.token_ttl'             => 'token_ttl',
        'security.login_fail_limit' => 'login_fail_limit',
        'security.login_lock_time'  => 'login_lock_time',
        'security.pwd_min_length'   => 'pwd_min_length',
        'security.pwd_max_length'   => 'pwd_max_length',
        'security.captcha_login'    => 'login_captcha',
    ];

    /** 取配置键对应的 settings 扁平键；无映射返回 null */
    public static function flatKey($key)
    {
        return isset(self::FLAT_MAP[$key]) ? self::FLAT_MAP[$key] : null;
    }

    /**
     * 环境变量注入（优先级最高）：UIC_{大写键名}，点号转下划线。
     *  例：uc_key -> UIC_UC_KEY，ucenter.url -> UIC_UCENTER_URL（同时认 uc_url -> UIC_UC_URL）
     * 用途：生产环境把密钥、UCenter 端点等敏感值放在进程环境里注入，
     *      既不硬编码进代码，也不落库被误改。
     */
    private static function envValue($key, $flatKey = null)
    {
        $candidates = [];
        if ($flatKey !== null && $flatKey !== '') {
            $candidates[] = $flatKey;
        }
        $candidates[] = $key;
        foreach ($candidates as $k) {
            $env = 'UIC_' . strtoupper(str_replace('.', '_', $k));
            $v   = getenv($env);
            if ($v !== false && $v !== '') {
                return $v;
            }
        }
        return null;
    }

    /** 读取配置，支持点号路径 如 'ucenter.url'；环境变量 > settings 表 > 文件默认 */
    public static function get($key, $default = null)
    {
        $d = self::defaults();
        $isNested = strpos($key, '.') !== false;
        $fk = $isNested ? self::flatKey($key) : null;
        // 环境变量优先级最高（生产注入敏感值）
        $env = self::envValue($key, $fk);
        if ($env !== null) {
            return $env;
        }
        if ($isNested) {
            list($seg, $sub) = explode('.', $key, 2);
            if (isset($d[$seg]) && is_array($d[$seg]) && array_key_exists($sub, $d[$seg])) {
                $default = $d[$seg][$sub];
            }
        } elseif (array_key_exists($key, $d)) {
            $default = $d[$key];
        }
        $flat = self::load();
        // M1：嵌套键若在 settings 表有扁平键（如 ucenter.url -> uc_url），取其运行值，修复"嵌套配置段永不生效"
        if ($isNested) {
            $fk = self::flatKey($key);
            if ($fk !== null && array_key_exists($fk, $flat)) {
                return $flat[$fk];
            }
        }
        return array_key_exists($key, $flat) ? $flat[$key] : $default;
    }

    /** 该键当前是否由环境变量注入（运维自检用，避免"改了库却不生效"的困惑） */
    public static function fromEnv($key)
    {
        $fk = strpos($key, '.') !== false ? self::flatKey($key) : null;
        return self::envValue($key, $fk) !== null;
    }

    public static function set($key, $value, $group = 'system', $remark = null)
    {
        // M1：嵌套键写入时落到 settings 扁平键，与读取规则保持一致
        $fk = self::flatKey($key);
        if ($fk !== null) {
            $key = $fk;
        }
        $db = Database::getInstance();
        $json = is_array($value) || is_bool($value) || is_int($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string)$value;
        // 先 UPDATE 再 INSERT IGNORE（MySQL 5.7 兼容，避免 ON DUPLICATE KEY 依赖）
        // 注意：必须走 $db->prepare() 而不是 getPdo()->prepare()，否则表前缀不会被注入
        $now  = time();
        $stmt = $db->prepare("UPDATE settings SET s_value=?, updated_at=? WHERE s_key=?");
        $stmt->execute([$json, $now, $key]);
        if ($stmt->rowCount() === 0) {
            $db->prepare(
                "INSERT IGNORE INTO settings(s_key,s_value,s_group,remark,updated_at) VALUES(?,?,?,?,?)"
            )->execute([$key, $json, $group, $remark, $now]);
        }
        self::$cache = null;
        return true;
    }

    public static function group($group)
    {
        $rows = Database::getInstance()->query("SELECT * FROM settings WHERE s_group=? ORDER BY s_key", [$group]);
        return $rows;
    }

    public static function remove($key)
    {
        self::$cache = null;
        return Database::getInstance()->delete('settings', 's_key=?', [$key]);
    }

    /** 清空配置缓存（UCenter 下发「更新缓存/更新客户端缓存」通知时调用） */
    public static function flush()
    {
        self::$cache = null;
        return true;
    }
}
