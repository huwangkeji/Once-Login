<?php
/**
 * 短信宝（smsbao）HTTP 短信接口
 * 文档：https://www.smsbao.com/openapi/
 * 特点：无需签名/模板报备，直接以「【签名】+正文」形式提交，适合快速联调与小量发送。
 */
class SmsBaoSmsProvider extends AbstractSmsProvider
{
    public static function key()
    {
        return 'smsbao';
    }

    public static function name()
    {
        return '短信宝';
    }

    public static function fields()
    {
        return [
            ['key' => 'user', 'label' => '平台账号', 'type' => 'text', 'required' => true, 'secret' => false,
             'placeholder' => '注册手机号', 'help' => '短信宝官网注册的用户名（一般为手机号）'],
            ['key' => 'pass', 'label' => '平台密码', 'type' => 'password', 'required' => true, 'secret' => true,
             'env' => 'UIC_SMS_SMSBAO_PASS', 'placeholder' => '登录密码',
             'help' => '接口以 md5(密码) 传输，此处填明文；可用环境变量注入'],
            ['key' => 'sign', 'label' => '短信签名', 'type' => 'text', 'required' => false, 'secret' => false,
             'placeholder' => '我的应用', 'help' => '会拼成【签名】作为内容前缀，3-8 个汉字，建议填写'],
        ];
    }

    public function send($mobile, $scene, $code, array $vars, array $cfg)
    {
        $user = (string)($cfg['user'] ?? '');
        $pass = (string)($cfg['pass'] ?? '');
        if ($user === '' || $pass === '') {
            return [false, '短信宝凭据不完整（账号/密码 缺一不可）'];
        }
        $sign  = (string)($cfg['sign'] ?? '');
        $label = self::sceneLabel($scene);
        $ttl   = intval(Config::get('sms_code_ttl', 600)) / 60;
        $min   = $ttl > 0 ? intval($ttl) : 10;

        $content = ($sign !== '' ? '【' . $sign . '】' : '')
            . '您的' . $label . '验证码是' . $code . '，' . $min . '分钟内有效，请勿泄露。';

        $url = 'https://api.smsbao.com/sms?u=' . rawurlencode($user)
            . '&p=' . rawurlencode(md5($pass))
            . '&m=' . rawurlencode($mobile)
            . '&c=' . rawurlencode($content);

        $resp = self::httpGet($url);
        if ($resp === null) {
            return [false, '短信宝接口请求失败（网络不可达或证书校验失败）'];
        }
        $k = trim($resp);
        if ($k === '0') {
            return [true, '短信已通过短信宝发送'];
        }
        $map = [
            '30' => '短信宝: 密码错误',
            '40' => '短信宝: 账号不存在',
            '41' => '短信宝: 余额不足',
            '42' => '短信宝: 账户过期',
            '43' => '短信宝: IP地址限制',
            '50' => '短信宝: 内容含敏感词',
            '51' => '短信宝: 手机号码不正确',
        ];
        return [false, $map[$k] ?? ('短信宝返回未知状态: ' . $k)];
    }

    public static function doc()
    {
        return [
            'summary' => '短信宝为免报备型 HTTP 短信接口，接入最快，适合开发联调与低量业务。',
            'prepare' => ['手机号注册的短信宝账号', '账户内有可用短信条数（新用户通常有赠送）'],
            'keyPath' => '官网注册后，在「用户中心 → 接口信息」查看账号与接口密码（即登录密码）。',
            'openSteps' => [
                '注册并登录短信宝官网。',
                '充值或领取免费短信条数。',
                '（可选）在后台设置常用签名，便于内容合规。',
                '记录服务器出口 IP，如需固定 IP 白名单请到后台配置。',
            ],
            'params' => [
                ['key' => 'user', 'desc' => '短信宝平台账号',     'example' => '13800000000'],
                ['key' => 'pass', 'desc' => '平台密码（明文）',   'example' => 'yourPassword'],
                ['key' => 'sign', 'desc' => '内容前缀签名（可选）','example' => '我的应用'],
            ],
            'verify' => '后台体检发送测试验证码，返回「0」即提交成功；也可在短信宝后台「发送记录」核对。',
            'errors' => [
                ['code' => '30', 'meaning' => '密码错误', 'fix' => '确认填写的是明文密码（系统会自动 md5），或重置密码'],
                ['code' => '40', 'meaning' => '账号不存在', 'fix' => '核对账号是否为注册手机号'],
                ['code' => '41', 'meaning' => '余额不足', 'fix' => '充值后再试'],
                ['code' => '42', 'meaning' => '账户过期', 'fix' => '联系短信宝续期'],
                ['code' => '43', 'meaning' => 'IP 地址限制', 'fix' => '在短信宝后台添加服务器出口 IP 白名单'],
                ['code' => '50', 'meaning' => '内容含敏感词', 'fix' => '调整签名词或正文措辞'],
                ['code' => '51', 'meaning' => '手机号码不正确', 'fix' => '校验手机号格式与号段'],
            ],
        ];
    }
}
