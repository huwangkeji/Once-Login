<?php
/**
 * 短信服务商抽象基类
 *
 * 承担三件与具体服务商无关的通用职责：
 *   1. 私有配置解析：DB(settings.sms_providers) → 环境变量 → 旧扁平键 → 默认值
 *      （密钥等 secret 字段优先支持环境变量注入，避免明文入库）
 *   2. 凭据完整性判定 / 敏感字段掩码
 *   3. HTTP 请求（严格 SSL 校验，复用项目自带 data/cacert.pem）
 *
 * 子类只需实现 key() / name() / fields() / send() / doc()。
 */
abstract class AbstractSmsProvider implements SmsProviderInterface
{
    /** 环境变量前缀 */
    const ENV_PREFIX = 'UIC_SMS_';

    /** 某字段对应的环境变量名 */
    public static function envName($fieldKey, $def = null)
    {
        if (!empty($def['env'])) {
            return $def['env'];
        }
        return self::ENV_PREFIX . strtoupper(static::key()) . '_' . strtoupper((string)$fieldKey);
    }

    /**
     * 解析私有配置
     * 优先级：settings.sms_providers.{provider}.{key} → 环境变量 → 旧扁平键 sms_{provider}_{key} → default
     */
    public static function resolveConfig()
    {
        $key  = static::key();
        $defs = static::fields();

        // 新模型：sms_providers 是一段 JSON，按服务商分段
        $all = Config::get('sms_providers', []);
        if (is_string($all)) {
            $decoded = json_decode($all, true);
            $all = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($all)) {
            $all = [];
        }
        $own = isset($all[$key]) && is_array($all[$key]) ? $all[$key] : [];

        $out = [];
        foreach ($defs as $f) {
            $fk = $f['key'];
            $v  = isset($own[$fk]) ? $own[$fk] : null;

            // 1) 环境变量注入（仅 secret 字段，或显式声明 env 的字段）
            if (($v === null || $v === '') && !empty($f['secret'])) {
                $env = self::envName($fk, $f);
                $ev  = getenv($env);
                if ($ev === false || $ev === '') {
                    $ev = isset($_ENV[$env]) ? $_ENV[$env] : (isset($_SERVER[$env]) ? $_SERVER[$env] : '');
                }
                if ($ev !== '' && $ev !== null) {
                    $v = $ev;
                }
            }

            // 2) 兼容旧扁平配置键（平滑迁移，不影响既有部署）
            if ($v === null || $v === '') {
                $legacy = Config::get('sms_' . $key . '_' . $fk, null);
                if ($legacy !== null && $legacy !== '') {
                    $v = $legacy;
                }
            }

            // 3) 默认值
            if ($v === null || $v === '') {
                $v = isset($f['default']) ? $f['default'] : '';
            }
            $out[$fk] = is_scalar($v) ? (string)$v : $v;
        }

        $out['enabled']  = !empty($own['enabled']) ? 1 : 0;
        $out['priority'] = isset($own['priority']) ? intval($own['priority']) : 50;
        return $out;
    }

    /** 必填凭据是否齐全（含环境变量注入结果） */
    public static function isConfigured()
    {
        $cfg = static::resolveConfig();
        foreach (static::fields() as $f) {
            if (empty($f['required'])) {
                continue;
            }
            $v = isset($cfg[$f['key']]) ? $cfg[$f['key']] : '';
            if ($v === '' || $v === null) {
                return false;
            }
        }
        return true;
    }

    /** 敏感字段掩码后的配置（后台展示用，不泄露完整密钥） */
    public static function maskConfig()
    {
        $cfg = static::resolveConfig();
        foreach (static::fields() as $f) {
            if (empty($f['secret'])) {
                continue;
            }
            $v = isset($cfg[$f['key']]) ? (string)$cfg[$f['key']] : '';
            if ($v === '') {
                continue;
            }
            $cfg[$f['key']] = mb_substr($v, 0, 3) . '****' . mb_substr($v, -3);
        }
        return $cfg;
    }

    /** 场景中文名（用于自定义内容类服务商） */
    protected static function sceneLabel($scene)
    {
        $map = ['register' => '注册', 'findpwd' => '找回密码', 'bind' => '绑定'];
        return isset($map[$scene]) ? $map[$scene] : (string)$scene;
    }

    // ==================== HTTP（严格 SSL 校验） ====================

    protected static function httpGet($url, $timeout = 15)
    {
        return self::request('GET', $url, null, [], $timeout);
    }

    protected static function httpPost($url, $body = null, $headers = [], $timeout = 15)
    {
        return self::request('POST', $url, $body, $headers, $timeout);
    }

    /**
     * @return string|null 失败返回 null
     */
    protected static function request($method, $url, $body = null, $headers = [], $timeout = 15)
    {
        if (function_exists('curl_init')) {
            $opts = [
                CURLOPT_RETURNTRANSFER  => true,
                CURLOPT_TIMEOUT         => intval($timeout),
                CURLOPT_CONNECTTIMEOUT  => 8,
                CURLOPT_SSL_VERIFYPEER  => true,
                CURLOPT_SSL_VERIFYHOST  => 2,
                CURLOPT_USERAGENT       => 'UIC-SMS/2.0',
            ];
            // 优先使用项目自带 CA 包（Windows 常未配置 php.ini curl.cainfo）
            $ca = BASE_PATH . '/data/cacert.pem';
            if (is_file($ca)) {
                $opts[CURLOPT_CAINFO] = $ca;
            }
            if ($method === 'POST') {
                $opts[CURLOPT_POST]       = true;
                $opts[CURLOPT_POSTFIELDS] = $body;
                if ($headers) {
                    $opts[CURLOPT_HTTPHEADER] = $headers;
                }
            }
            $ch = curl_init($url);
            curl_setopt_array($ch, $opts);
            $resp = curl_exec($ch);
            if ($resp === false) {
                curl_close($ch);
                return null;
            }
            curl_close($ch);
            return $resp;
        }

        // stream 兜底
        if (!function_exists('stream_socket_client')) {
            return null;
        }
        $parts  = parse_url($url);
        if (empty($parts['host'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme'] ?? 'https');
        $port   = $parts['port'] ?? ($scheme === 'http' ? 80 : 443);
        $path   = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        $ssl    = ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false, 'SNI_enabled' => true];
        $ca     = BASE_PATH . '/data/cacert.pem';
        if (is_file($ca)) {
            $ssl['cafile'] = $ca;
        }
        $ctx  = stream_context_create(['ssl' => $ssl]);
        $fp   = @stream_socket_client($scheme . '://' . $parts['host'] . ':' . $port, $errno, $errstr, intval($timeout), STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            return null;
        }
        $req  = strtoupper($method) . ' ' . $path . " HTTP/1.1\r\n";
        $req .= 'Host: ' . $parts['host'] . "\r\nUser-Agent: UIC-SMS/2.0\r\nAccept: */*\r\n";
        if ($body !== null) {
            $req .= 'Content-Type: application/json; charset=utf-8' . "\r\n";
            $req .= 'Content-Length: ' . strlen($body) . "\r\n";
        }
        foreach ($headers as $h) {
            $req .= $h . "\r\n";
        }
        $req .= "Connection: close\r\n\r\n";
        if ($body !== null) {
            $req .= $body;
        }
        stream_set_timeout($fp, intval($timeout));
        fwrite($fp, $req);
        $resp = '';
        while (!feof($fp)) {
            $resp .= fgets($fp, 8192);
        }
        fclose($fp);
        $pos     = strpos($resp, "\r\n\r\n");
        $header  = $pos === false ? '' : substr($resp, 0, $pos);
        $content = $pos === false ? '' : substr($resp, $pos + 4);
        if (!preg_match('#^HTTP/\d\.\d\s+2\d\d#', $header)) {
            return null;
        }
        return $content;
    }

    /** 阿里云 RPC 风格百分号编码 */
    protected static function percentEncode($s)
    {
        $s = rawurlencode((string)$s);
        $s = str_replace('+', '%20', $s);
        $s = str_replace('*', '%2A', $s);
        return str_replace('%7E', '~', $s);
    }
}
