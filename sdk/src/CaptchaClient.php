<?php
namespace UIC\SDK;

use UIC\SDK\Http\Request;
use UIC\SDK\Exception\ApiException;

/**
 * 图形验证码客户端
 */
class CaptchaClient
{
    private $http;

    public function __construct(Config $config, Request $http)
    {
        $this->http = $http;
    }

    /**
     * 获取验证码
     *
     * @param string $style 可选 slide(滑动拼图)/click(文字点选)/icon(图标选择)/passive(无感验证)/digits(纯数字)/mixed(数字+字母)
     * @return array {token, style, ...}
     */
    public function issue(string $style = ''): array
    {
        $params = [];
        if ($style !== '') {
            $params['style'] = $style;
        }
        $res = $this->http->send('POST', '?m=captcha&a=issue', $params);
        return $res['data'] ?? [];
    }

    /**
     * 校验验证码
     *
     * @param string $token
     * @param string $answer
     * @return bool
     */
    public function verify(string $token, string $answer): bool
    {
        try {
            $this->http->send('POST', '?m=captcha&a=verify', [
                'token'  => $token,
                'answer' => $answer,
            ]);
            return true;
        } catch (ApiException $e) {
            return false;
        }
    }
}
