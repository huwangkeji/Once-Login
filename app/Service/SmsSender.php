<?php
/**
 * 短信网关门面（兼容层）
 *
 * 说明：真正的实现已迁移到可插拔架构
 *   app/Core/Sms/SmsProviderInterface.php   服务商契约
 *   app/Core/Sms/AbstractSmsProvider.php    配置解析 / 环境变量注入 / HTTP
 *   app/Core/Sms/{Tencent,SmsBao,Aliyun,Huawei}SmsProvider.php
 *   app/Core/Sms/SmsProviderRegistry.php    注册表（新增服务商的唯一入口）
 *   app/Core/Sms/SmsGateway.php             核心发送逻辑 + 公共配置 + 调度策略
 *
 * 本类保留原有调用签名（VerifyModel 等既有业务无需改动），内部转发到 SmsGateway。
 */
class SmsSender
{
    /** 可用服务商清单（后台下拉选项） */
    public static function providers()
    {
        return SmsProviderRegistry::options();
    }

    /** 生成验证码内容串 */
    public static function makeCode()
    {
        return SmsGateway::makeCode();
    }

    /**
     * 发送短信验证码
     * @param string $mobile
     * @param string $scene register / findpwd / bind
     * @param string $code
     * @return array [bool, string]
     */
    public static function send($mobile, $scene, $code)
    {
        return SmsGateway::send($mobile, $scene, $code);
    }

    /** 服务商凭据是否已完整配置 */
    public static function configured($provider = null)
    {
        if ($provider === null || $provider === '') {
            $provider = (string)Config::get('sms_provider', '');
        }
        if ($provider === '') {
            return false;
        }
        $cls = SmsProviderRegistry::get($provider);
        if (!$cls) {
            return false;
        }
        return $cls::isConfigured();
    }

    /** 体检信息（后台展示） */
    public static function diagnose()
    {
        return SmsGateway::diagnose();
    }

    /** 公共配置（有效期 / 频率限制 / 模板变量 / 策略） */
    public static function commonConfig()
    {
        return SmsGateway::commonConfig();
    }
}
