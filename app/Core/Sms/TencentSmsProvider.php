<?php
/**
 * 腾讯云短信（API 3.0 / TC3-HMAC-SHA256）
 * 文档：https://cloud.tencent.com/document/product/382
 */
class TencentSmsProvider extends AbstractSmsProvider
{
    public static function key()
    {
        return 'tencent';
    }

    public static function name()
    {
        return '腾讯云短信';
    }

    public static function fields()
    {
        return [
            ['key' => 'secret_id', 'label' => 'SecretId', 'type' => 'password', 'required' => true, 'secret' => true,
             'env' => 'UIC_SMS_TENCENT_SECRET_ID', 'placeholder' => 'AKIDxxxxxxxx',
             'help' => '访问管理 CAM → 密钥管理 → 新建密钥，环境变量可注入'],
            ['key' => 'secret_key', 'label' => 'SecretKey', 'type' => 'password', 'required' => true, 'secret' => true,
             'env' => 'UIC_SMS_TENCENT_SECRET_KEY', 'placeholder' => '仅创建时可见，请妥善保存',
             'help' => '与 SecretId 成对生成，泄露请立即禁用'],
            ['key' => 'sdk_app_id', 'label' => 'SdkAppId', 'type' => 'text', 'required' => true, 'secret' => false,
             'placeholder' => '1400000000', 'help' => '短信控制台 → 应用管理 → 应用列表里的 SDK AppID'],
            ['key' => 'sign', 'label' => '短信签名', 'type' => 'text', 'required' => true, 'secret' => false,
             'placeholder' => '腾讯云', 'help' => '需审核通过，填写签名内容（不含【】）'],
            ['key' => 'template_id', 'label' => '模板 ID', 'type' => 'text', 'required' => true, 'secret' => false,
             'placeholder' => '1234567', 'help' => '国内短信 → 正文模板管理，模板须含 {1} 变量'],
            ['key' => 'region', 'label' => '地域', 'type' => 'text', 'required' => false, 'secret' => false,
             'default' => 'ap-guangzhou', 'placeholder' => 'ap-guangzhou', 'help' => '一般保持默认'],
        ];
    }

    public function send($mobile, $scene, $code, array $vars, array $cfg)
    {
        $secretId  = (string)($cfg['secret_id'] ?? '');
        $secretKey = (string)($cfg['secret_key'] ?? '');
        $appId     = (string)($cfg['sdk_app_id'] ?? '');
        $sign      = (string)($cfg['sign'] ?? '');
        $tplId     = (string)($cfg['template_id'] ?? '');
        if ($secretId === '' || $secretKey === '' || $appId === '' || $sign === '' || $tplId === '') {
            return [false, '腾讯云短信凭据不完整（SecretId/SecretKey/SdkAppId/签名/模板ID 缺一不可）'];
        }
        $region = (string)($cfg['region'] ?? 'ap-guangzhou');
        if ($region === '') {
            $region = 'ap-guangzhou';
        }

        $endpoint = 'sms.tencentcloudapi.com';
        $service  = 'sms';
        $action   = 'SendSms';
        $version  = '2019-07-11';

        // 腾讯云用位置参数 {1}{2}，按模板变量定义顺序取值
        $payload = [
            'PhoneNumberSet'   => ['+86' . $mobile],
            'SmsSdkAppId'      => $appId,
            'SignName'         => $sign,
            'TemplateId'       => $tplId,
            'TemplateParamSet' => array_values($vars),
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

        $t         = time();
        $date      = gmdate('Y-m-d', $t);
        $timestamp = $t;

        $canonicalHeaders = "content-type:application/json; charset=utf-8\nhost:" . $endpoint . "\nx-tc-action:" . strtolower($action) . "\n";
        $signedHeaders    = 'content-type;host;x-tc-action';
        $hashedPayload    = hash('sha256', $json);
        $canonicalRequest = "POST\n/\n\n" . $canonicalHeaders . "\n" . $signedHeaders . "\n" . $hashedPayload;
        $credentialScope  = $date . '/' . $service . '/tc3_request';
        $stringToSign     = "TC3-HMAC-SHA256\n" . $timestamp . "\n" . $credentialScope . "\n" . hash('sha256', $canonicalRequest);

        $secretDate    = hash_hmac('sha256', $date, 'TC3' . $secretKey, true);
        $secretService = hash_hmac('sha256', $service, $secretDate, true);
        $secretSigning = hash_hmac('sha256', 'tc3_request', $secretService, true);
        $signature     = hash_hmac('sha256', $stringToSign, $secretSigning);

        $authorization = 'TC3-HMAC-SHA256 Credential=' . $secretId . '/' . $credentialScope
            . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature;

        $headers = [
            'Content-Type: application/json; charset=utf-8',
            'Host: ' . $endpoint,
            'X-TC-Action: ' . $action,
            'X-TC-Timestamp: ' . $timestamp,
            'X-TC-Version: ' . $version,
            'X-TC-Region: ' . $region,
            'Authorization: ' . $authorization,
        ];

        $resp = self::httpPost('https://' . $endpoint . '/', $json, $headers);
        if ($resp === null) {
            return [false, '腾讯云短信接口请求失败（网络不可达或证书校验失败）'];
        }
        $data = json_decode($resp, true);
        if (!is_array($data)) {
            return [false, '腾讯云短信响应解析失败: ' . mb_substr($resp, 0, 120)];
        }
        if (!empty($data['Response']['Error'])) {
            $e = $data['Response']['Error'];
            return [false, '腾讯云短信错误: ' . ($e['Code'] ?? '') . ' ' . ($e['Message'] ?? '')];
        }
        $set = $data['Response']['SendStatusSet'][0] ?? [];
        if (($set['Code'] ?? '') === 'Ok') {
            return [true, '短信已通过腾讯云发送'];
        }
        return [false, '腾讯云短信发送被拒: ' . ($set['Message'] ?? mb_substr($resp, 0, 120))];
    }

    public static function doc()
    {
        return [
            'summary' => '腾讯云短信 SMS API 3.0，国内验证码场景主流选择，签名采用 TC3-HMAC-SHA256。',
            'prepare' => ['实名认证的腾讯云账号', '已完成企业/个人实名', '可接收短信的测试手机号'],
            'keyPath' => '控制台 → 访问管理 CAM → 密钥管理 → 新建密钥，得到 SecretId / SecretKey。',
            'openSteps' => [
                '开通「短信 SMS」服务。',
                '应用管理 → 创建应用，拿到 SdkAppId。',
                '国内短信 → 签名管理，提交签名（需营业执照或公众号截图），等待审核。',
                '国内短信 → 正文模板管理，创建模板如「您的验证码是{1}，5分钟内有效」。',
            ],
            'params' => [
                ['key' => 'secret_id',   'desc' => 'CAM 密钥 ID',        'example' => 'AKIDz8krbsJ5yKBZQpn74WFkmLPx3EXAMPLE'],
                ['key' => 'secret_key',  'desc' => 'CAM 密钥 Key',       'example' => 'Gu5t9xGARNpq86cd98joQYCN3EXAMPLE'],
                ['key' => 'sdk_app_id',  'desc' => '短信应用 SDK AppID', 'example' => '1400000000'],
                ['key' => 'sign',        'desc' => '已审核通过的签名内容','example' => '我的应用'],
                ['key' => 'template_id', 'desc' => '正文模板 ID',        'example' => '1234567'],
                ['key' => 'region',      'desc' => '地域，一般默认',      'example' => 'ap-guangzhou'],
            ],
            'verify' => '后台「短信服务」体检发送测试验证码；或在控制台「发送统计」查看记录。',
            'errors' => [
                ['code' => 'AuthFailure.SignatureExpire', 'meaning' => '签名过期', 'fix' => '检查服务器时间是否与标准时间同步（NTP）'],
                ['code' => 'AuthFailure.InvalidSecretId', 'meaning' => '密钥不存在或被禁用', 'fix' => '核对 SecretId，或到 CAM 重新启用'],
                ['code' => 'InvalidParameterValue.IncorrectPhoneNumber', 'meaning' => '手机号格式错误', 'fix' => '需带国家码 +86'],
                ['code' => 'InvalidParameterValue.TemplateParamSetFormatError', 'meaning' => '模板变量数量不匹配', 'fix' => '模板变量个数与模板占位符一致'],
                ['code' => 'LimitExceeded.PhoneNumberThirtySecondLimit', 'meaning' => '30 秒内重复发送', 'fix' => '放慢频率或开启频率限制'],
                ['code' => 'FailedOperation.SignatureIncorrectOrUnapproved', 'meaning' => '签名未审批或与报备不符', 'fix' => '确认签名已审核且与填写完全一致'],
            ],
        ];
    }
}
