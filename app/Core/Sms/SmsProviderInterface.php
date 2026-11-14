<?php
/**
 * 短信服务商接口（可插拔接入层的契约）
 *
 * 新增服务商只需：
 *   1. 在 app/Core/Sms/ 下新建类 implements SmsProviderInterface（通常继承 AbstractSmsProvider）
 *   2. 在 SmsProviderRegistry::defaults() 中注册一行
 * 核心发送逻辑（SmsGateway）与业务层（VerifyModel / SmsSender）均无需改动。
 */
interface SmsProviderInterface
{
    /** 服务商标识（配置键，如 tencent / aliyun），全局唯一、小写 */
    public static function key();

    /** 服务商显示名（后台与文档用） */
    public static function name();

    /**
     * 私有配置字段定义（各服务商自己的账号/密钥/接口参数）
     *
     * 返回结构（每项为一个关联数组）：
     *   key         string  字段键，最终存储到 sms_providers.{provider}.{key}
     *   label       string  中文名（后台表单用）
     *   type        string  text / password / textarea / select
     *   required    bool    是否必填（决定 isConfigured 判定）
     *   secret      bool    是否敏感信息（true 时支持环境变量注入、后台默认掩码显示）
     *   env         string  环境变量名，缺省 UIC_SMS_{PROVIDER}_{KEY}
     *   placeholder string  输入框占位提示
     *   help        string  填写说明（含获取路径）
     *   options     array   type=select 时的选项 [值=>显示名]
     *   default     mixed   默认值
     */
    public static function fields();

    /**
     * 发送短信验证码
     *
     * @param string $mobile 手机号（已通过格式校验，不含国际码）
     * @param string $scene  场景：register / findpwd / bind
     * @param string $code   验证码原文
     * @param array  $vars   模板变量键值对（由公共配置的模板变量名决定，如 ['code'=>'123456']）
     * @param array  $cfg    已解析的私有配置（含环境变量注入结果、enabled、priority）
     * @return array [bool $ok, string $message]
     */
    public function send($mobile, $scene, $code, array $vars, array $cfg);

    /** 文档章节（配置教程）所需的结构化信息，供后台/文档页展示 */
    public static function doc();
}
