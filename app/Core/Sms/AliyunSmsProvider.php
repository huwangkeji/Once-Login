<?php
/**
 * 阿里云短信服务（Dysmsapi / RPC 风格签名 HMAC-SHA1）
 * 文档：https://help.aliyun.com/product/44282.html
 */
class AliyunSmsProvider extends AbstractSmsProvider
{
    public static function key()
    {
        return 'aliyun';
    }

    public static function name()
    {
        return '阿里云短信';
    }

    public static function fields()
    {
        return [
            ['key' => 'access_key_id', 'label' => 'AccessKeyId', 'type' => 'password', 'required' => true, 'secret' => true,
             'env' => 'UIC_SMS_ALIYUN_ACCESS_KEY_ID', 'placeholder' => 'LTAIxxxxxxxx',
             'help' => 'RAM 访问控制 → 用户 → AccessKey，建议使用子账号并仅授予短信权限'],
            ['key' => 'access_key_secret', 'label' => 'AccessKeySecret', 'type' => 'password', 'required' => true, 'secret' => true,
             'env' => 'UIC_SMS_ALIYUN_ACCESS_KEY_SECRET', 'placeholder' => '仅在创建时可见',
             'help' => '与 AccessKeyId 成对，泄露请立即禁用'],
            ['key' => 'sign_name', 'label' => '短信签名', 'type' => 'text', 'required' => true, 'secret' => false,
             'placeholder' => '我的应用', 'help' => '需审核通过，填写签名名称（不含【】）'],
            ['key' => 'template_code', 'label' => '模板 CODE', 'type' => 'text', 'required' => true, 'secret' => false,
             'placeholder' => 'SMS_123456789', 'help' => '模板管理里的模板 CODE，变量形如 ${code}'],
            ['key' => 'region', 'label' => 'RegionId', 'type' => 'text', 'required' => false, 'secret' => false,
             'default' => 'cn-hangzhou', 'placeholder' => 'cn-hangzhou', 'help' => '一般保持默认'],
        ];
    }

    public function send($mobile, $scene, $code, array $vars, array $cfg)
    {
        $ak       = (string)($cfg['access_key_id'] ?? '');
        $sk       = (string)($cfg['access_key_secret'] ?? '');
        $signName = (string)($cfg['sign_name'] ?? '');
        $tplCode  = (string)($cfg['template_code'] ?? '');
        if ($ak === '' || $sk === '' || $signName === '' || $tplCode === '') {
            return [false, '阿里云短信凭据不完整（AccessKeyId/AccessKeySecret/签名/模板CODE 缺一不可）'];
        }
        $region = (string)($cfg['region'] ?? 'cn-hangzhou');
        if ($region === '') {
            $region = 'cn-hangzhou';
        }

        $params = [
            'Action'            => 'SendSms',
            'Version'           => '2017-05-25',
            'Format'            => 'JSON',
            'RegionId'          => $region,
            'AccessKeyId'       => $ak,
            'SignatureMethod'   => 'HMAC-SHA1',
            'SignatureVersion'  => '1.0',
            'SignatureNonce'    => bin2hex(random_bytes(8)),
            'Timestamp'         => gmdate('Y-m-d\TH:i:s\Z'),
            'PhoneNumbers'      => $mobile,
            'SignName'          => $signName,
            'TemplateCode'      => $tplCode,
            'TemplateParam'     => json_encode($vars, JSON_UNESCAPED_UNICODE),
        ];
        ksort($params);
        $sorted = [];
        foreach ($params as $k => $v) {
            $sorted[] = self::percentEncode($k) . '=' . self::percentEncode((string)$v);
        }
        $stringToSign = 'GET&%2F&' . self::percentEncode(implode('&', $sorted));
        $signature    = base64_encode(hash_hmac('sha1', $stringToSign, $sk . '&', true));
        $params['Signature'] = $signature;

        $query = [];
        foreach ($params as $k => $v) {
            $query[] = self::percentEncode($k) . '=' . self::percentEncode((string)$v);
        }
        $url  = 'https://dysmsapi.aliyuncs.com/?' . implode('&', $query);
        $resp = self::httpGet($url);
        if ($resp === null) {
            return [false, '阿里云短信接口请求失败（网络不可达或证书校验失败）'];
        }
        $data = json_decode($resp, true);
        if (!is_array($data)) {
            return [false, '阿里云短信响应解析失败: ' . mb_substr($resp, 0, 120)];
        }
        if (($data['Code'] ?? '') === 'OK') {
            return [true, '短信已通过阿里云发送'];
        }
        return [false, '阿里云短信错误: ' . ($data['Code'] ?? '') . ' ' . ($data['Message'] ?? mb_substr($resp, 0, 120))];
    }

    public static function doc()
    {
        return [
            'summary' => '阿里云短信服务，签名与模板需报备，适合生产环境稳定发送。',
            'prepare' => ['实名认证的阿里云账号', '已完成企业实名（个人实名签名审核较严）', '测试手机号'],
            'keyPath' => 'RAM 访问控制 → 用户 → 创建子用户 → 创建 AccessKey，得到 AccessKeyId / AccessKeySecret。',
            'openSteps' => [
                '开通「短信服务 SMS」。',
                '国内消息 → 签名管理 → 添加签名（需资质证明），等待审核。',
                '国内消息 → 模板管理 → 添加模板，正文含 ${code} 变量，等待审核。',
                '给子账号授予 AliyunDysmsFullAccess（或只读发送的最小权限）。',
            ],
            'params' => [
                ['key' => 'access_key_id',     'desc' => 'RAM AccessKey ID',   'example' => 'LTAI4GEXAMPLE'],
                ['key' => 'access_key_secret', 'desc' => 'RAM AccessKey Secret','example' => 'yourSecret'],
                ['key' => 'sign_name',         'desc' => '已审核签名名称',      'example' => '我的应用'],
                ['key' => 'template_code',     'desc' => '已审核模板 CODE',     'example' => 'SMS_123456789'],
                ['key' => 'region',            'desc' => 'RegionId',            'example' => 'cn-hangzhou'],
            ],
            'verify' => '后台体检发送测试验证码；或在「业务统计 → 发送记录」查询流水。',
            'errors' => [
                ['code' => 'InvalidAccessKeyId.NotFound', 'meaning' => 'AccessKey 不存在', 'fix' => '核对 AK 或改用有效子账号 AK'],
                ['code' => 'SignatureDoesNotMatch',       'meaning' => '签名不匹配',       'fix' => '检查 Secret 是否正确、参数是否被二次编码'],
                ['code' => 'InvalidDayuStatus.MsgKeyInvalid', 'meaning' => '签名未审批',   'fix' => '确认签名已审核通过且名称完全一致'],
                ['code' => 'isv.SMS_TEMPLATE_ILLEGAL',    'meaning' => '模板不合法或未审批', 'fix' => '检查 TemplateCode 与模板状态'],
                ['code' => 'isv.BUSINESS_LIMIT_CONTROL',  'meaning' => '触发流控',         'fix' => '放慢发送频率，或申请提升配额'],
                ['code' => 'isv.MOBILE_NUMBER_ILLEGAL',   'meaning' => '手机号非法',       'fix' => '校验号段与格式'],
                ['code' => 'isv.AMOUNT_NOT_ENOUGH',       'meaning' => '余额不足',         'fix' => '充值短信套餐包'],
            ],
        ];
    }
}
