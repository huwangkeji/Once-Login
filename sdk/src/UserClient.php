<?php
namespace UIC\SDK;

use UIC\SDK\Http\Request;
use UIC\SDK\Config;
use UIC\SDK\Exception\ApiException;

/**
 * 用户业务客户端（需 Cookie 会话态）
 * 适用于前端 JS 通过代理或后端持有用户 Cookie 的场景
 */
class UserClient
{
    private $http;
    private $config;

    public function __construct(Config $config, Request $http)
    {
        $this->config = $config;
        $this->http = $http;
    }

    /**
     * 用户注册
     *
     * @param array $data {username, password, confirm?, email?, mobile?, nickname?, gender?}
     * @param bool  $autoLogin 注册后自动登录
     * @return array {uid, ...}
     */
    public function register(array $data, bool $autoLogin = true): array
    {
        $params = array_merge($data, ['auto_login' => $autoLogin ? '1' : '0']);
        $res = $this->http->send('POST', '?m=user&a=register', $params);
        return $res['data'] ?? [];
    }

    /**
     * 用户登录
     *
     * @param string $account  用户名/邮箱/手机号
     * @param string $password
     * @param bool   $remember 记住登录态
     * @return array 用户信息
     */
    public function login(string $account, string $password, bool $remember = false): array
    {
        $res = $this->http->send('POST', '?m=user&a=login', [
            'account'  => $account,
            'password' => $password,
            'remember' => $remember ? '1' : '0',
        ]);
        return $res['data'] ?? [];
    }

    /**
     * 退出当前设备
     */
    public function logout(): bool
    {
        try {
            $this->http->send('POST', '?m=user&a=logout');
            return true;
        } catch (ApiException $e) {
            return false;
        }
    }

    /**
     * 退出全部设备
     */
    public function logoutAll(): bool
    {
        try {
            $this->http->send('POST', '?m=user&a=logoutall');
            return true;
        } catch (ApiException $e) {
            return false;
        }
    }

    /**
     * 获取当前登录用户信息（需会话 Cookie）
     */
    public function getInfo(): array
    {
        $res = $this->http->send('POST', '?m=user&a=info');
        return $res['data'] ?? [];
    }

    /**
     * 获取账号安全信息（设备 + 历史）
     */
    public function getSecurity(): array
    {
        $res = $this->http->send('POST', '?m=user&a=security');
        return $res['data'] ?? [];
    }

    /**
     * 踢掉指定设备
     */
    public function killDevice(string $sid): bool
    {
        try {
            $this->http->send('POST', '?m=user&a=device_kill', ['sid' => $sid]);
            return true;
        } catch (ApiException $e) {
            return false;
        }
    }

    /**
     * 修改密码
     */
    public function changePassword(string $oldPassword, string $newPassword, string $confirm): array
    {
        $res = $this->http->send('POST', '?m=user&a=password', [
            'old_password' => $oldPassword,
            'new_password' => $newPassword,
            'confirm'      => $confirm,
        ]);
        return $res['data'] ?? [];
    }

    /**
     * 修改资料
     */
    public function updateProfile(array $data): array
    {
        $res = $this->http->send('POST', '?m=user&a=profile', $data);
        return $res['data'] ?? [];
    }

    /**
     * 发送找回密码验证码
     */
    public function sendResetCode(string $target): bool
    {
        try {
            $this->http->send('POST', '?m=user&a=findpwd_send', ['target' => $target]);
            return true;
        } catch (ApiException $e) {
            return false;
        }
    }

    /**
     * 找回密码 - 重置
     */
    public function resetPassword(string $target, string $code, string $newPassword, string $confirm): array
    {
        $res = $this->http->send('POST', '?m=user&a=findpwd_reset', [
            'target'     => $target,
            'code'       => $code,
            'new_password' => $newPassword,
            'confirm'    => $confirm,
        ]);
        return $res['data'] ?? [];
    }

    /**
     * 唯一性检查
     *
     * @param string $field username|email|mobile
     * @param string $value
     * @return bool true=可用
     */
    public function checkUnique(string $field, string $value): bool
    {
        $res = $this->http->send('POST', '?m=user&a=check', [
            'field' => $field,
            'value' => $value,
        ]);
        return ($res['data'] ?? false) === true;
    }

    /**
     * 批量查询用户信息
     *
     * 逐条调用 info 接口并汇总结果；单条失败不中断，
     * 失败条目以 ['_error' => 错误信息] 标记。
     *
     * @param array $tokens token 列表（每个元素为 access_token）
     * @param int   $concurrency 并发上限（预留，当前按顺序执行）
     * @return array{success: array, failed: int}
     */
    public function batchInfo(array $tokens, int $concurrency = 5): array
    {
        if (empty($tokens)) {
            return ['success' => [], 'failed' => 0];
        }
        $concurrency = max(1, $concurrency);
        $success = [];
        $failed = 0;

        foreach (array_values($tokens) as $i => $token) {
            try {
                $res = $this->http->send('POST', '?m=sso&a=userinfo', [
                    'token'  => (string) $token,
                    'app_id' => $this->config->get('app_id'),
                ]);
                $success[(string) $token] = $res['data'] ?? [];
            } catch (\Exception $e) {
                $failed++;
                $success[(string) $token] = ['_error' => $e->getMessage()];
            }
        }
        return ['success' => $success, 'failed' => $failed];
    }

    /**
     * 批量注册（逐条调用 register 接口，单条失败不中断）
     *
     * @param array $users  用户数据列表，每个元素为 register 的 data
     * @param bool  $autoLogin
     * @return array{success: array, failed: int}
     */
    public function batchRegister(array $users, bool $autoLogin = true): array
    {
        if (empty($users)) {
            return ['success' => [], 'failed' => 0];
        }
        $success = [];
        $failed = 0;
        foreach (array_values($users) as $i => $data) {
            try {
                $res = $this->http->send('POST', '?m=user&a=register', array_merge(
                    (array) $data,
                    ['auto_login' => $autoLogin ? '1' : '0']
                ));
                $success[] = $res['data'] ?? [];
            } catch (\Exception $e) {
                $failed++;
                $success[] = ['_error' => $e->getMessage()];
            }
        }
        return ['success' => $success, 'failed' => $failed];
    }
}
