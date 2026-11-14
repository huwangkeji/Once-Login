<?php
/**
 * 第三方 OAuth2 登录统一抽象
 * 覆盖：QQ / 微信 / 百度 / 支付宝 / GitHub / Google / 微软 / 华为 / 小米 / HBuilder / 新浪微博
 *
 * 统一流程：authorize → code → access_token → userinfo → openid → 绑定/创建账号
 * 各平台差异（QQ 需额外取 openid、支付宝需 RSA2 签名、GitHub 需 UA 等）
 * 通过 providers() 中的 flow 与字段映射适配，业务层无需关心。
 */
class OAuth
{
    /** 统一回调入口（相对站点根） */
    const CALLBACK_PATH = '/oauth.php';

    /** 平台能力表 */
    public static function providers()
    {
        return [
            'qq' => [
                'name'   => 'QQ',
                'color'  => '#12b7f5',
                'flow'   => 'oauth2',
                'scope'  => 'get_user_info',
                'openid' => 'qq_me',              // 需额外请求 /oauth2.0/me 取 openid
                'authorize' => 'https://graph.qq.com/oauth2.0/authorize',
                'token'     => 'https://graph.qq.com/oauth2.0/token',
                'me'        => 'https://graph.qq.com/oauth2.0/me',
                'userinfo'  => 'https://graph.qq.com/user/get_user_info',
                'fields'    => ['nickname' => 'nickname', 'avatar' => 'figureurl_qq_2'],
                'extra_token' => ['fmt' => 'json'],
            ],
            'wechat' => [
                'name'   => '微信',
                'color'  => '#07c160',
                'flow'   => 'oauth2',
                'scope'  => 'snsapi_login',
                'openid' => 'token',              // token 响应直接含 openid / unionid
                'authorize' => 'https://open.weixin.qq.com/connect/qrconnect',
                'token'     => 'https://api.weixin.qq.com/sns/oauth2/access_token',
                'userinfo'  => 'https://api.weixin.qq.com/sns/userinfo',
                'fields'    => ['nickname' => 'nickname', 'avatar' => 'headimgurl'],
                'fragment'  => 'wechat_redirect',
            ],
            'baidu' => [
                'name'   => '百度',
                'color'  => '#2932e1',
                'flow'   => 'oauth2',
                'scope'  => 'basic',
                'openid' => 'userinfo',
                'authorize' => 'https://openapi.baidu.com/oauth/2.0/authorize',
                'token'     => 'https://openapi.baidu.com/oauth/2.0/token',
                'userinfo'  => 'https://openapi.baidu.com/rest/2.0/passport/users/getInfo',
                'fields'    => ['openid' => 'userid', 'nickname' => 'username', 'avatar' => 'portrait'],
            ],
            'alipay' => [
                'name'   => '支付宝',
                'color'  => '#1677ff',
                'flow'   => 'alipay',             // 需 RSA2 签名
                'scope'  => 'auth_user',
                'openid' => 'alipay',
                'authorize' => 'https://openauth.alipay.com/oauth2/publicAppAuthorize.htm',
                'gateway'   => 'https://openapi.alipay.com/gateway.do',
                'fields'    => ['openid' => 'user_id', 'nickname' => 'nick_name', 'avatar' => 'avatar'],
            ],
            'github' => [
                'name'   => 'GitHub',
                'color'  => '#24292f',
                'flow'   => 'oauth2',
                'scope'  => 'read:user',
                'openid' => 'userinfo',
                'authorize' => 'https://github.com/login/oauth/authorize',
                'token'     => 'https://github.com/login/oauth/access_token',
                'userinfo'  => 'https://api.github.com/user',
                'fields'    => ['openid' => 'id', 'nickname' => 'login', 'avatar' => 'avatar_url'],
                'json_token' => true,             // 需 Accept: application/json
                'ua'         => 'UIC-OAuth',      // GitHub 强制要求 User-Agent
            ],
            'google' => [
                'name'   => 'Google',
                'color'  => '#4285f4',
                'flow'   => 'oauth2',
                'scope'  => 'openid profile email',
                'openid' => 'userinfo',
                'authorize' => 'https://accounts.google.com/o/oauth2/v2/auth',
                'token'     => 'https://oauth2.googleapis.com/token',
                'userinfo'  => 'https://www.googleapis.com/oauth2/v3/userinfo',
                'fields'    => ['openid' => 'sub', 'nickname' => 'name', 'avatar' => 'picture'],
                'bearer'    => true,
            ],
            'microsoft' => [
                'name'   => '微软',
                'color'  => '#00a4ef',
                'flow'   => 'oauth2',
                'scope'  => 'openid profile email',
                'openid' => 'userinfo',
                'authorize' => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
                'token'     => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
                'userinfo'  => 'https://graph.microsoft.com/oidc/userinfo',
                'fields'    => ['openid' => 'sub', 'nickname' => 'name', 'avatar' => 'picture'],
                'bearer'    => true,
                'extra_authorize' => ['response_mode' => 'query'],
            ],
            'huawei' => [
                'name'   => '华为',
                'color'  => '#cf0a2c',
                'flow'   => 'oauth2',
                'scope'  => 'openid profile',
                'openid' => 'userinfo',
                'authorize' => 'https://oauth-login.cloud.huawei.com/oauth2/v3/authorize',
                'token'     => 'https://oauth-login.cloud.huawei.com/oauth2/v3/token',
                'userinfo'  => 'https://oauth-login.cloud.huawei.com/oauth2/v3/userinfo',
                'fields'    => ['openid' => 'sub', 'nickname' => 'display_name', 'avatar' => 'picture'],
                'token_post' => true,
            ],
            'xiaomi' => [
                'name'   => '小米',
                'color'  => '#ff6700',
                'flow'   => 'xiaomi',
                'scope'  => 'user/profile',
                'openid' => 'userinfo',
                'authorize' => 'https://account.xiaomi.com/oauth2/authorize',
                'token'     => 'https://account.xiaomi.com/oauth2/token',
                'userinfo'  => 'https://open.account.xiaomi.com/user/profile',
                'fields'    => ['openid' => 'userId', 'nickname' => 'userName', 'avatar' => 'portrait'],
                'data_wrap' => 'data',            // 返回体包裹在 data 中
                'token_post' => true,
            ],
            'hbuilder' => [
                'name'   => 'HBuilder',
                'color'  => '#41b883',
                'flow'   => 'oauth2',
                'scope'  => 'basic',
                'openid' => 'userinfo',
                'authorize' => 'https://account.dcloud.net.cn/oauth2/authorize',
                'token'     => 'https://account.dcloud.net.cn/oauth2/token',
                'userinfo'  => 'https://account.dcloud.net.cn/api/user/info',
                'fields'    => ['openid' => 'uid', 'nickname' => 'nickname', 'avatar' => 'avatar'],
                'token_post' => true,
            ],
            'weibo' => [
                'name'   => '新浪微博',
                'color'  => '#e6162d',
                'flow'   => 'weibo',              // token 响应直接含 uid
                'scope'  => '',
                'openid' => 'token',
                'authorize' => 'https://api.weibo.com/oauth2/authorize',
                'token'     => 'https://api.weibo.com/oauth2/access_token',
                'userinfo'  => 'https://api.weibo.com/2/users/show.json',
                'fields'    => ['openid' => 'uid', 'nickname' => 'name', 'avatar' => 'profile_image_url'],
                'token_post' => true,
            ],
        ];
    }

    public static function get($provider)
    {
        $p = self::providers();
        return isset($p[$provider]) ? $p[$provider] : null;
    }

    /** 读取后台配置（settings.oauth_{provider}，JSON） */
    public static function config($provider)
    {
        $raw = Config::get('oauth_' . $provider, '');
        if (is_array($raw)) {
            return $raw;
        }
        $d = json_decode((string)$raw, true);
        return is_array($d) ? $d : [];
    }

    public static function isEnabled($provider)
    {
        $p = self::get($provider);
        if (!$p) {
            return false;
        }
        $c = self::config($provider);
        return !empty($c['enabled']) && !empty($c['appid']) && !empty($c['secret']);
    }

    /** 已启用的平台列表 */
    public static function enabled()
    {
        $out = [];
        foreach (self::providers() as $k => $p) {
            if (self::isEnabled($k)) {
                $out[$k] = $p;
            }
        }
        return $out;
    }

    /** 统一回调地址（后台展示 + 平台侧填写） */
    public static function callbackUrl($provider)
    {
        return rtrim(site_url(''), '/') . self::CALLBACK_PATH . '?provider=' . urlencode($provider) . '&action=callback';
    }

    /** 构造授权跳转地址 */
    public static function authorizeUrl($provider, $state)
    {
        $p = self::get($provider);
        $c = self::config($provider);
        if (!$p) {
            throw new RuntimeException('不支持的第三方登录平台');
        }
        $cb = self::callbackUrl($provider);
        $q  = [
            'response_type' => 'code',
            'client_id'     => (string)($c['appid'] ?? ''),
            'redirect_uri'  => $cb,
            'state'         => $state,
        ];
        if (!empty($p['scope'])) {
            $q['scope'] = $p['scope'];
        }
        // 支付宝用 app_id / scope=auth_user
        if ($p['flow'] === 'alipay') {
            $q = [
                'app_id'       => (string)($c['appid'] ?? ''),
                'scope'        => $p['scope'],
                'redirect_uri' => $cb,
                'state'        => $state,
            ];
        }
        if (!empty($p['extra_authorize'])) {
            $q = array_merge($q, $p['extra_authorize']);
        }
        $url = $p['authorize'] . '?' . http_build_query($q);
        if (!empty($p['fragment'])) {
            $url .= '#' . $p['fragment'];
        }
        return $url;
    }

    /** 用 code 换 access_token */
    public static function accessToken($provider, $code)
    {
        $p = self::get($provider);
        $c = self::config($provider);
        if (!$p) {
            throw new RuntimeException('不支持的第三方登录平台');
        }
        $cb = self::callbackUrl($provider);
        $params = [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'client_id'     => (string)($c['appid'] ?? ''),
            'client_secret' => (string)($c['secret'] ?? ''),
            'redirect_uri'  => $cb,
        ];

        // ---- 支付宝：OpenAPI 网关 + RSA2 签名 ----
        if ($p['flow'] === 'alipay') {
            return self::alipayToken($provider, $code, $c, $cb);
        }
        // ---- 小米：token 用 clientId 而非 client_id ----
        if ($p['flow'] === 'xiaomi') {
            $params = [
                'grant_type'    => 'authorization_code',
                'code'          => $code,
                'client_id'     => (string)($c['appid'] ?? ''),
                'client_secret' => (string)($c['secret'] ?? ''),
                'redirect_uri'  => $cb,
            ];
        }
        if (!empty($p['extra_token'])) {
            $params = array_merge($params, $p['extra_token']);
        }

        $method = !empty($p['token_post']) ? 'POST' : 'GET';
        $headers = [];
        if (!empty($p['json_token'])) {
            $headers[] = 'Accept: application/json';
        }
        if (!empty($p['ua'])) {
            $headers[] = 'User-Agent: ' . $p['ua'];
        }
        $raw = self::http($p['token'], $params, $method, $headers);
        $data = self::decode($raw);
        if (empty($data['access_token'])) {
            throw new RuntimeException('获取 access_token 失败：' . mb_substr($raw, 0, 200));
        }
        return $data;
    }

    /** 支付宝：alipay.system.oauth.token（RSA2 签名） */
    private static function alipayToken($provider, $code, $c, $cb)
    {
        $p = self::get($provider);
        $biz = ['grant_type' => 'authorization_code', 'code' => $code];
        $common = [
            'app_id'      => (string)($c['appid'] ?? ''),
            'method'      => 'alipay.system.oauth.token',
            'format'      => 'JSON',
            'charset'     => 'UTF-8',
            'sign_type'   => 'RSA2',
            'timestamp'   => date('Y-m-d H:i:s'),
            'version'     => '1.0',
            'biz_content' => json_encode($biz, JSON_UNESCAPED_UNICODE),
        ];
        $priv = (string)($c['private_key'] ?? '');
        if ($priv === '') {
            throw new RuntimeException('支付宝登录需填写应用私钥（private_key）');
        }
        $common['sign'] = self::rsa2Sign(self::buildQuery($common), $priv);
        $raw = self::http($p['gateway'], $common, 'POST');
        $data = self::decode($raw);
        $resp = isset($data['alipay_system_oauth_token_response']) ? $data['alipay_system_oauth_token_response'] : [];
        if (empty($resp['access_token'])) {
            throw new RuntimeException('支付宝换取 token 失败：' . mb_substr($raw, 0, 200));
        }
        $resp['openid'] = $resp['user_id'] ?? '';
        return $resp;
    }

    /** 拉取第三方用户信息，返回统一结构 */
    public static function userInfo($provider, $tokenData)
    {
        $p = self::get($provider);
        $c = self::config($provider);
        if (!$p) {
            throw new RuntimeException('不支持的第三方登录平台');
        }
        $at   = (string)($tokenData['access_token'] ?? '');
        $f    = $p['fields'];
        $openid = '';

        // ---- 取 openid ----
        if ($p['openid'] === 'qq_me') {
            $raw = self::http($p['me'], ['access_token' => $at, 'fmt' => 'json'], 'GET');
            $me  = self::decode($raw);
            if (empty($me['openid'])) {
                // 兼容 callback({...}); 包裹
                if (preg_match('/"openid"\s*:\s*"([^"]+)"/', $raw, $m)) {
                    $me['openid'] = $m[1];
                }
            }
            if (empty($me['openid'])) {
                throw new RuntimeException('QQ 获取 openid 失败');
            }
            $openid = $me['openid'];
        } elseif ($p['openid'] === 'token') {
            $openid = (string)($tokenData['openid'] ?? $tokenData['uid'] ?? '');
        } elseif ($p['openid'] === 'alipay') {
            $openid = (string)($tokenData['user_id'] ?? $tokenData['openid'] ?? '');
        }

        // ---- 取用户资料 ----
        $params = ['access_token' => $at];
        if ($provider === 'qq') {
            $params['oauth_consumer_key'] = (string)($c['appid'] ?? '');
            $params['openid']             = $openid;
        }
        if ($p['flow'] === 'weibo') {
            $params['uid'] = $openid;
        }
        if ($p['flow'] === 'xiaomi') {
            $params = ['clientId' => (string)($c['appid'] ?? ''), 'token' => $at];
        }
        $headers = [];
        if (!empty($p['bearer'])) {
            $headers[] = 'Authorization: Bearer ' . $at;
        }
        if (!empty($p['ua'])) {
            $headers[] = 'User-Agent: ' . $p['ua'];
        }

        $raw  = self::http($p['userinfo'], $params, 'GET', $headers);
        $info = self::decode($raw);
        if (!empty($p['data_wrap']) && isset($info[$p['data_wrap']])) {
            $info = $info[$p['data_wrap']];
        }
        // 支付宝：alipay.user.info.share
        if ($p['flow'] === 'alipay') {
            $info = self::alipayUserInfo($provider, $at, $c);
        }

        if ($p['openid'] === 'userinfo' && isset($f['openid'])) {
            $openid = (string)($info[$f['openid']] ?? '');
        }
        if ($openid === '') {
            throw new RuntimeException('第三方账号 openid 为空，无法绑定');
        }

        $nick = '';
        if (!empty($f['nickname'])) {
            $nick = (string)($info[$f['nickname']] ?? '');
        }
        $avatar = '';
        if (!empty($f['avatar'])) {
            $avatar = (string)($info[$f['avatar']] ?? '');
        }

        return [
            'openid'   => $openid,
            'unionid'  => (string)($tokenData['unionid'] ?? $info['unionid'] ?? ''),
            'nickname' => $nick,
            'avatar'   => $avatar,
            'raw'      => $info,
            'token'    => $at,
            'refresh'  => (string)($tokenData['refresh_token'] ?? ''),
            'expires'  => intval($tokenData['expires_in'] ?? 0),
        ];
    }

    /** 支付宝：alipay.user.info.share */
    private static function alipayUserInfo($provider, $at, $c)
    {
        $p = self::get($provider);
        $common = [
            'app_id'      => (string)($c['appid'] ?? ''),
            'method'      => 'alipay.user.info.share',
            'format'      => 'JSON',
            'charset'     => 'UTF-8',
            'sign_type'   => 'RSA2',
            'timestamp'   => date('Y-m-d H:i:s'),
            'version'     => '1.0',
            'auth_token'  => $at,
        ];
        $priv = (string)($c['private_key'] ?? '');
        $common['sign'] = self::rsa2Sign(self::buildQuery($common), $priv);
        $raw  = self::http($p['gateway'], $common, 'POST');
        $data = self::decode($raw);
        return isset($data['alipay_user_info_share_response']) ? $data['alipay_user_info_share_response'] : [];
    }

    // ==================== HTTP / 工具 ====================

    public static function http($url, $params = [], $method = 'GET', $headers = [])
    {
        $method = strtoupper($method);
        if ($method === 'GET' && $params) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($params);
        }
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
            }
            if ($headers) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            }
            $res = curl_exec($ch);
            if ($res === false) {
                $err = curl_error($ch);
                curl_close($ch);
                throw new RuntimeException('第三方接口请求失败：' . $err);
            }
            curl_close($ch);
            return $res;
        }
        // 退化为 stream
        $ctx = ['http' => ['timeout' => 15, 'ignore_errors' => true]];
        if ($method === 'POST') {
            $ctx['http']['method']  = 'POST';
            $ctx['http']['header']  = "Content-Type: application/x-www-form-urlencoded\r\n";
            $ctx['http']['content'] = http_build_query($params);
        }
        if ($headers) {
            $ctx['http']['header'] = implode("\r\n", $headers) . "\r\n";
        }
        $res = @file_get_contents($url, false, stream_context_create($ctx));
        if ($res === false) {
            throw new RuntimeException('第三方接口请求失败（stream）');
        }
        return $res;
    }

    /** 兼容 JSON 与 query-string 响应 */
    private static function decode($raw)
    {
        $raw = trim((string)$raw);
        $d = json_decode($raw, true);
        if (is_array($d)) {
            return $d;
        }
        $out = [];
        parse_str($raw, $out);
        return $out;
    }

    /** 待签名串：参数按 key 升序、剔除 sign/空值 */
    private static function buildQuery($params)
    {
        ksort($params);
        $arr = [];
        foreach ($params as $k => $v) {
            if ($k === 'sign' || $v === '' || $v === null) {
                continue;
            }
            $arr[] = $k . '=' . $v;
        }
        return implode('&', $arr);
    }

    private static function rsa2Sign($data, $privateKey)
    {
        $key = "-----BEGIN RSA PRIVATE KEY-----\n"
            . chunk_split(preg_replace('/\s+/', '', $privateKey), 64, "\n")
            . "-----END RSA PRIVATE KEY-----";
        $res = openssl_get_privatekey($key);
        if (!$res) {
            // 兼容 PKCS#8
            $key = "-----BEGIN PRIVATE KEY-----\n"
                . chunk_split(preg_replace('/\s+/', '', $privateKey), 64, "\n")
                . "-----END PRIVATE KEY-----";
            $res = openssl_get_privatekey($key);
        }
        if (!$res) {
            throw new RuntimeException('支付宝应用私钥格式不正确');
        }
        openssl_sign($data, $sign, $res, OPENSSL_ALGO_SHA256);
        openssl_free_key($res);
        return base64_encode($sign);
    }

    // ==================== 各平台配置说明 ====================

    /**
     * 平台接入文档：申请入口 / 密钥说明 / 回调地址 / 参数填写
     * 用于后台「第三方登录」设置页逐平台展示
     */
    public static function docs($provider)
    {
        $cb = self::callbackUrl($provider);
        $map = [
            'qq' => [
                'apply' => 'https://connect.qq.com/',
                'steps' => [
                    '登录 QQ 互联（connect.qq.com）→ 应用管理 → 创建网站应用，需备案域名。',
                    '审核通过后，在应用详情获取 <b>APP ID</b> 与 <b>APP Key</b>。',
                    '在应用「回调地址」中填写下方回调地址（需与代码完全一致，含参数）。',
                    '本系统使用 scope=get_user_info，仅获取昵称与头像。',
                ],
                'fields' => ['appid' => 'APP ID', 'secret' => 'APP Key'],
            ],
            'wechat' => [
                'apply' => 'https://open.weixin.qq.com/',
                'steps' => [
                    '登录微信开放平台（open.weixin.qq.com）→ 管理中心 → 网站应用 → 创建应用（需 300 元认证）。',
                    '审核通过后获取 <b>AppID</b> 与 <b>AppSecret</b>。',
                    '在应用「授权回调域」填写本站域名（不带 http，如 auth.example.com）；完整回调地址填下方地址。',
                    '本系统使用 snsapi_login（扫码登录），可获取 openid / unionid / 昵称 / 头像。',
                ],
                'fields' => ['appid' => 'AppID', 'secret' => 'AppSecret'],
            ],
            'baidu' => [
                'apply' => 'https://developer.baidu.com/',
                'steps' => [
                    '登录百度开发者中心 → 创建应用，类型选「Web 应用」。',
                    '获取 <b>API Key（client_id）</b> 与 <b>Secret Key（client_secret）</b>。',
                    '在「安全设置 → 授权回调地址」填写下方回调地址。',
                ],
                'fields' => ['appid' => 'API Key', 'secret' => 'Secret Key'],
            ],
            'alipay' => [
                'apply' => 'https://open.alipay.com/',
                'steps' => [
                    '登录支付宝开放平台 → 创建「网页/移动应用」，并添加功能 <b>获取会员信息</b>。',
                    '获取 <b>AppID</b>；在「接口加签方式」生成应用公私钥，上传应用公钥得到支付宝公钥。',
                    '将 <b>应用私钥（PKCS#1 或 PKCS#8 均可）</b>填入私钥框，AppID 填入 AppID 框，Secret 可留空。',
                    '在应用「授权回调地址」填写下方回调地址。',
                ],
                'fields' => ['appid' => 'AppID', 'secret' => '留空即可', 'private_key' => '应用私钥（RSA2）'],
            ],
            'github' => [
                'apply' => 'https://github.com/settings/developers',
                'steps' => [
                    'GitHub → Settings → Developer settings → OAuth Apps → New OAuth App。',
                    '填写 Homepage URL 与 <b>Authorization callback URL</b>（下方回调地址）。',
                    '创建后获取 <b>Client ID</b>，并 Generate a new client secret 得到 <b>Client secret</b>。',
                ],
                'fields' => ['appid' => 'Client ID', 'secret' => 'Client secret'],
            ],
            'google' => [
                'apply' => 'https://console.cloud.google.com/apis/credentials',
                'steps' => [
                    'Google Cloud 控制台 → API 和服务 → 凭据 → 创建 OAuth 客户端 ID（Web 应用）。',
                    '在「已授权的重定向 URI」填写下方回调地址。',
                    '获取 <b>客户端 ID</b> 与 <b>客户端密钥</b>。',
                ],
                'fields' => ['appid' => '客户端 ID', 'secret' => '客户端密钥'],
            ],
            'microsoft' => [
                'apply' => 'https://portal.azure.com/#blade/Microsoft_AAD_RegisteredApps',
                'steps' => [
                    'Azure 门户 → 应用注册 → 新注册；平台类型选「Web」，重定向 URI 填下方回调地址。',
                    '在「证书和密码」新建客户端密码，得到 <b>客户端密码</b>。',
                    '概览页获取 <b>应用程序(客户端) ID</b>。',
                ],
                'fields' => ['appid' => '应用程序(客户端) ID', 'secret' => '客户端密码'],
            ],
            'huawei' => [
                'apply' => 'https://developer.huawei.com/consumer/cn/console',
                'steps' => [
                    '华为开发者联盟 → 我的项目 → 新建项目并添加应用。',
                    '在「API 管理」开启 <b>Account Kit</b>（获取用户信息）。',
                    '在项目设置中获取 <b>Client ID</b> 与 <b>Client Secret</b>，并配置回调地址为下方地址。',
                ],
                'fields' => ['appid' => 'Client ID', 'secret' => 'Client Secret'],
            ],
            'xiaomi' => [
                'apply' => 'https://dev.mi.com/console',
                'steps' => [
                    '小米开放平台 → 创建 Web 应用，获取 <b>AppID</b> 与 <b>AppKey</b>。',
                    '在应用「回调地址」填写下方回调地址。',
                    '需申请 user/profile 权限。',
                ],
                'fields' => ['appid' => 'AppID', 'secret' => 'AppKey'],
            ],
            'hbuilder' => [
                'apply' => 'https://account.dcloud.net.cn/',
                'steps' => [
                    'DCloud 开发者中心 → 应用管理 → 创建 OAuth 应用。',
                    '获取 <b>Client ID</b> 与 <b>Client Secret</b>。',
                    '回调地址填写下方地址。',
                ],
                'fields' => ['appid' => 'Client ID', 'secret' => 'Client Secret'],
            ],
            'weibo' => [
                'apply' => 'https://open.weibo.com/apps',
                'steps' => [
                    '新浪微博开放平台 → 我的应用 → 创建网页应用。',
                    '获取 <b>App Key</b> 与 <b>App Secret</b>。',
                    '在「高级信息 → OAuth2.0 授权设置」中，授权回调页填写下方回调地址。',
                ],
                'fields' => ['appid' => 'App Key', 'secret' => 'App Secret'],
            ],
        ];
        $d = isset($map[$provider]) ? $map[$provider] : ['apply' => '', 'steps' => [], 'fields' => []];
        $d['callback'] = $cb;
        return $d;
    }
}
