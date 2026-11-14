<?php
/**
 * 华为云短信（KooMessage / 消息&短信 MSGSMS）batchSendSms v1
 * 鉴权：WSSE UsernameToken（PasswordDigest = Base64(SHA256(Nonce + Created + AppSecret))）
 * 文档：https://support.huaweicloud.com/api-msgsms/
 */
class HuaweiSmsProvider extends AbstractSmsProvider
{
    public static function key()
    {
        return 'huawei';
    }

    public static function name()
    {
        return '华为云短信';
    }

    public static function fields()
    {
        return [
            ['key' => 'app_key', 'label' => 'APP Key', 'type' => 'password', 'required' => true, 'secret' => true,
             'env' => 'UIC_SMS_HUAWEI_APP_KEY', 'placeholder' => '应用管理 → 应用详情里的 APP Key',
             'help' => '控制台「应用管理 → 应用详情」查看，可用环境变量注入'],
            ['key' => 'app_secret', 'label' => 'APP Secret', 'type' => 'password', 'required' => true, 'secret' => true,
             'env' => 'UIC_SMS_HUAWEI_APP_SECRET', 'placeholder' => '应用详情里的 APP Secret',
             'help' => '与 APP Key 成对，泄露请重新生成'],
            ['key' => 'api_url', 'label' => '接口地址', 'type' => 'text', 'required' => false, 'secret' => false,
             'default' => 'https://rtcsms.cn-north-1.huaweicloud.com:10743/sms/batchSendSms/v1',
             'placeholder' => 'https://rtcsms.cn-north-1.huaweicloud.com:10743/sms/batchSendSms/v1',
             'help' => '控制台「应用详情 → APP 接入地址」，地域不同地址不同'],
            ['key' => 'signature', 'label' => '短信签名', 'type' => 'text', 'required' => true, 'secret' => false,
             'placeholder' => '我的应用', 'help' => '已审核通过的签名名称（不含【】）'],
            ['key' => 'template_id', 'label' => '模板 ID', 'type' => 'text', 'required' => true, 'secret' => false,
             'placeholder' => '8ff55eac1c1b4d0e9d7e6f8a2b3c4d5e', 'help' => '模板管理里的模板 ID，变量形如 ${1}'],
            ['key' => 'from', 'label' => '通道号', 'type' => 'text', 'required' => false, 'secret' => false,
             'placeholder' => '8823099xxxxx', 'help' => '国内短信的通道号；若签名已绑定通道可留空'],
            ['key' => 'status_callback', 'label' => '状态回调地址', 'type' => 'text', 'required' => false, 'secret' => false,
             'placeholder' => 'https://your.site/api/sms/report', 'help' => '可选，接收发送状态报告'],
        ];
    }

    public function send($mobile, $scene, $code, array $vars, array $cfg)
    {
        $appKey    = (string)($cfg['app_key'] ?? '');
        $appSecret = (string)($cfg['app_secret'] ?? '');
        $signature = (string)($cfg['signature'] ?? '');
        $tplId     = (string)($cfg['template_id'] ?? '');
        if ($appKey === '' || $appSecret === '' || $signature === '' || $tplId === '') {
            return [false, '华为云短信凭据不完整（APP Key/APP Secret/签名/模板ID 缺一不可）'];
        }
        $apiUrl = (string)($cfg['api_url'] ?? '');
        if ($apiUrl === '') {
            $apiUrl = 'https://rtcsms.cn-north-1.huaweicloud.com:10743/sms/batchSendSms/v1';
        }

        // WSSE 摘要
        $created = gmdate('Y-m-d\TH:i:s\Z');
        $nonce   = bin2hex(random_bytes(8));
        $digest  = base64_encode(hash('sha256', $nonce . $created . $appSecret, true));
        $wsse    = 'UsernameToken Username="' . $appKey . '",PasswordDigest="' . $digest
            . '",Nonce="' . $nonce . '",Created="' . $created . '"';

        $body = [
            'to'            => ['+86' . $mobile],
            'templateId'    => $tplId,
            'templateParas' => array_values($vars),
            'signature'     => $signature,
        ];
        if (($cfg['from'] ?? '') !== '') {
            $body['from'] = (string)$cfg['from'];
        }
        if (($cfg['status_callback'] ?? '') !== '') {
            $body['statusCallback'] = (string)$cfg['status_callback'];
        }
        $json = json_encode($body, JSON_UNESCAPED_UNICODE);

        $headers = [
            'Content-Type: application/json; charset=utf-8',
            'Authorization: WSSE realm="SDP",profile="UsernameToken",type="Appkey"',
            'X-WSSE: ' . $wsse,
        ];

        $resp = self::httpPost($apiUrl, $json, $headers);
        if ($resp === null) {
            return [false, '华为云短信接口请求失败（网络不可达、端口不通或证书校验失败）'];
        }
        $data = json_decode($resp, true);
        if (!is_array($data)) {
            return [false, '华为云短信响应解析失败: ' . mb_substr($resp, 0, 120)];
        }
        $code2 = (string)($data['code'] ?? '');
        if ($code2 === '' ) {
            // 部分版本只返回 result
            $r0 = $data['result'][0] ?? [];
            $code2 = (string)($r0['status'] ?? '');
        }
        if ($code2 === '000000') {
            return [true, '短信已通过华为云发送'];
        }
        return [false, '华为云短信错误: ' . $code2 . ' ' . ($data['description'] ?? mb_substr($resp, 0, 120))];
    }

    public static function doc()
    {
        return [
            'summary' => '华为云消息&短信服务（MSGSMS），采用 WSSE 鉴权，支持状态回调。',
            'prepare' => ['实名认证的华为云账号', '已完成企业实名', '测试手机号'],
            'keyPath' => '控制台 → 消息&短信 MSGSMS → 应用管理 → 创建应用 → 应用详情，查看 APP Key、APP Secret 与 APP 接入地址。',
            'openSteps' => [
                '开通「消息&短信 MSGSMS」服务。',
                '应用管理 → 创建应用，记录 APP Key / APP Secret / APP 接入地址。',
                '签名管理 → 申请签名（需资质），等待审核。',
                '模板管理 → 创建模板，变量形如 ${1}，等待审核。',
                '（可选）配置状态回调地址接收发送报告。',
            ],
            'params' => [
                ['key' => 'app_key',         'desc' => '应用 APP Key',        'example' => 'c8RWg3ggEcyd4D3p94bf3Y7x1Ile'],
                ['key' => 'app_secret',      'desc' => '应用 APP Secret',     'example' => 'q4Ii87BhST9vcs8wTB6I60VrK'],
                ['key' => 'api_url',         'desc' => 'APP 接入地址',        'example' => 'https://rtcsms.cn-north-1.huaweicloud.com:10743/sms/batchSendSms/v1'],
                ['key' => 'signature',       'desc' => '已审核签名名称',      'example' => '我的应用'],
                ['key' => 'template_id',     'desc' => '已审核模板 ID',      'example' => '8ff55eac1c1b4d0e9d7e6f8a2b3c4d5e'],
                ['key' => 'from',            'desc' => '通道号（可选）',      'example' => '8823099xxxxx'],
                ['key' => 'status_callback', 'desc' => '状态回调地址（可选）','example' => 'https://your.site/api/sms/report'],
            ],
            'verify' => '后台体检发送测试验证码；或在控制台「发送详情」查询，也可通过状态回调确认。',
            'errors' => [
                ['code' => 'E000102', 'meaning' => 'APP Key 或 APP Secret 错误', 'fix' => '核对应用详情中的密钥'],
                ['code' => 'E000103', 'meaning' => '鉴权失败/时间偏差', 'fix' => '检查服务器时间（NTP）与 Created 时间'],
                ['code' => 'E000110', 'meaning' => '模板未审批或不存在', 'fix' => '确认模板 ID 与审核状态'],
                ['code' => 'E000108', 'meaning' => '签名未审批', 'fix' => '确认签名已审核且名称一致'],
                ['code' => 'E000510', 'meaning' => '触发流控', 'fix' => '降低频率或申请提额'],
                ['code' => 'E000512', 'meaning' => '余额不足', 'fix' => '充值短信套餐'],
                ['code' => 'E000406', 'meaning' => '手机号格式错误', 'fix' => '需带国家码 +86'],
            ],
        ];
    }
}
