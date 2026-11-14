<?php
/**
 * UCenter HTTP 驱动（标准 UCenter 1.5/1.6 通信协议）
 *
 * 协议要点：
 *   1. 请求参数拼成 query string 后，整体经 authcode(input, ENCODE, UC_KEY) 加密
 *   2. 加密串作为 input 字段 POST 到 UC_URL/index.php?m=user&a=xxx&inajax=2&input=...&appid=...
 *   3. UCenter 返回一段同样经 authcode 加密的数据（xml_serialize 格式）
 *   4. 这里做容错解析：优先尝试标准的 input=xxx 形式，失败则直接 authcode 解密整段内容
 */
class HttpDriver implements UCenterDriver
{
    private $cfg;
    private const HTTP_UA = 'UICenterClient/1.0';
    public $lastRequest  = '';
    public $lastResponse = '';

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
    }

    public function call($module, $action, array $args = [])
    {
        foreach ($args as $k => $v) {
            if (is_bool($v)) {
                $args[$k] = intval($v);
            }
        }
        $extra    = http_build_query($args);
        $input    = Authcode::encode(
            $extra . '&m=' . $module . '&a=' . $action . '&appid=' . $this->cfg['appid'] . '&agent=' . md5(self::HTTP_UA) . '&time=' . time(),
            $this->cfg['key']
        );
        $post = http_build_query([
            'm'       => $module,
            'a'       => $action,
            'inajax'  => 2,
            'release' => '20090121',
            'input'   => $input,
            'appid'   => $this->cfg['appid'],
        ]);
        $this->lastRequest = $post;

        $url = rtrim($this->cfg['url'], '/') . '/index.php';
        $raw = $this->httpPost($url, $post, $this->cfg['timeout'], $this->cfg['ip']);
        $this->lastResponse = $raw;

        if ($raw === false || $raw === '') {
            throw new RuntimeException('UCenter 无响应，请检查 UC_URL 与网络连通性');
        }
        return $this->parse($raw);
    }

    /** 解析 UCenter 返回 */
    private function parse($raw)
    {
        $key = $this->cfg['key'];
        $data = null;

        // 形式一：input=加密串（UCenter 较新版本 + 带 entropy）
        if (strpos($raw, 'input=') !== false) {
            parse_str($raw, $arr);
            if (!empty($arr['input'])) {
                $data = Authcode::decode($arr['input'], $key);
            }
        }
        // 形式二：整段就是加密串
        if ($data === null || $data === '') {
            $data = Authcode::decode(trim($raw), $key);
        }
        // 形式三：明文 XML / JSON（测试或某些版本）
        if ($data === null || $data === '') {
            $data = trim($raw);
        }

        $xml = $data;
        // 剥离 CDATA 标记（远端 UCenter 2.0 返回值用 <![CDATA[...]]> 包裹）
        $xml = preg_replace('/<!\[CDATA\[([\s\S]*?)\]\]>/s', '$1', $xml);
        $xml = trim($xml);
        if (strpos($xml, '<root>') === false && strpos($xml, '<item') === false) {
            // 既非 XML 也非标准格式，直接返回原文
            return $xml;
        }
        $res = XmlKit::unserialize($xml);
        if (empty($res)) {
            return $xml;
        }
        return $this->flatten($res);
    }

    /** 把 xml_unserialize 结果整理成 int / array */
    private function flatten($res)
    {
        if (isset($res['item'])) {
            $res = $res['item'];
        }
        $isList = true;
        foreach ($res as $k => $v) {
            if (!is_int($k)) {
                $isList = false;
                break;
            }
        }
        if ($isList) {
            ksort($res);
            return count($res) === 1 ? reset($res) : array_values($res);
        }
        return $res;
    }

    private function httpPost($url, $post, $timeout = 10, $ip = '')
    {
        // 支持 UC_IP 直连（Host 仍用原域名）
        $host = parse_url($url, PHP_URL_HOST);
        $directIp = trim($ip ?: '');
        if ($directIp) {
            $url = str_replace($host, $directIp, $url);
        }

        // HTTPS 校验策略：uc_ssl_verify 总开关 + uc_ca_file 指定根证书包。
        // Windows 下 php.ini 常未配置 curl.cainfo / openssl.cafile，HTTPS 会报
        // "unable to get local issuer certificate" 从而表现为「UCenter 无响应」。
        // 因此缺省自动使用项目自带的 data/cacert.pem（可用 uc_ca_file 覆盖）。
        $sslVerify = (bool)Config::get('uc_ssl_verify', true);
        $caFile    = trim((string)Config::get('uc_ca_file', ''));
        if ($caFile === '') {
            $auto = BASE_PATH . '/data/cacert.pem';
            if (is_file($auto)) {
                $caFile = $auto;
            }
        }
        $caFile = ($caFile !== '' && is_file($caFile)) ? $caFile : '';

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $opts = [
                CURLOPT_RETURNTRANSFER  => true,
                CURLOPT_POST            => true,
                CURLOPT_POSTFIELDS      => $post,
                CURLOPT_TIMEOUT         => intval($timeout),
                CURLOPT_CONNECTTIMEOUT  => intval($timeout),
                CURLOPT_SSL_VERIFYPEER  => $sslVerify,
                CURLOPT_SSL_VERIFYHOST  => $sslVerify ? 2 : 0,
                CURLOPT_USERAGENT       => self::HTTP_UA,
            ];
            if ($sslVerify && $caFile !== '') {
                $opts[CURLOPT_CAINFO] = $caFile;
            }
            if ($directIp && $host) {
                $opts[CURLOPT_HTTPHEADER] = ['Host: ' . $host];
            }
            curl_setopt_array($ch, $opts);
            $ret = curl_exec($ch);
            curl_close($ch);
            return $ret;
        }

        // stream_socket_client 兜底（无 curl 扩展时），同样支持 cafile
        $parts   = parse_url($url);
        $isHttps = ($parts['scheme'] ?? 'http') === 'https';
        $scheme  = $isHttps ? 'ssl://' : '';
        $port    = $parts['port'] ?? ($isHttps ? 443 : 80);
        $ctxOpts = [];
        if ($isHttps) {
            $ssl = [
                'verify_peer'      => $sslVerify,
                'verify_peer_name' => $sslVerify,
            ];
            if ($sslVerify && $caFile !== '') {
                $ssl['cafile'] = $caFile;
            }
            $ctxOpts['ssl'] = $ssl;
        }
        $ctx = stream_context_create($ctxOpts);
        $fp  = @stream_socket_client(
            $scheme . $parts['host'] . ':' . $port,
            $errno, $errstr, intval($timeout),
            STREAM_CLIENT_CONNECT, $ctx
        );
        if (!$fp) {
            return false;
        }
        $out = "POST {$parts['path']} HTTP/1.0\r\n";
        $out .= "Host: " . ($directIp && $host ? $host : $parts['host']) . "\r\n";
        $out .= "Content-Type: application/x-www-form-urlencoded\r\n";
        $out .= "Content-Length: " . strlen($post) . "\r\n";
        $out .= "Connection: Close\r\n\r\n" . $post;
        fwrite($fp, $out);
        $res = '';
        while (!feof($fp)) {
            $res .= fgets($fp, 128);
        }
        fclose($fp);
        $pos = strpos($res, "\r\n\r\n");
        return $pos !== false ? substr($res, $pos + 4) : $res;
    }

    public function ping()
    {
        $t = microtime(true);
        try {
            $r = $this->call('user', 'check_username', ['username' => 'uic_ping_' . mt_rand(1000, 9999)]);
            return [
                'ok'      => true,
                'message' => 'UCenter HTTP 接口可达',
                'detail'  => ['echo' => $r, 'cost_ms' => round((microtime(true) - $t) * 1000)],
            ];
        } catch (Exception $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'detail' => []];
        }
    }
}
