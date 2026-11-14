<?php
/**
 * SSO 单点登录服务
 */
class SsoService
{
    /**
     * 应用侧跳转认证入口时调用：
     *  - 已登录 -> 签发 ticket 并 302 回回调地址
     *  - 未登录 -> 跳登录页，携带 ?app_id=xx&redirect=回调
     */
    public static function authorize($appId, $callback = '', $extra = [])
    {
        $app = AppModel::findByAppId($appId);
        if (!$app || !$app['status']) {
            return ['ok' => false, 'message' => '应用不存在或已被禁用'];
        }
        if (!$app['sso_enabled']) {
            return ['ok' => false, 'message' => '该应用未开启单点登录'];
        }
        if ($callback === '') {
            $cb = is_array($app['callbacks']) ? ($app['callbacks'][0] ?? '') : '';
            if ($cb === '') {
                return ['ok' => false, 'message' => '应用未配置回调地址'];
            }
            $callback = $cb;
        }
        if (!AppModel::allowCallback($app, $callback)) {
            return ['ok' => false, 'message' => '回调地址不在白名单内'];
        }

        $cur = UserSession::current();
        if (!$cur) {
            return ['ok' => false, 'need_login' => true, 'app' => self::publicApp($app), 'callback' => $callback, 'extra' => $extra];
        }
        $ticket = SsoModel::issueTicket(intval($cur['uid']), $cur['username'], $appId, $callback);
        Logger::info('sso', '签发 SSO Ticket', [
            'uid' => intval($cur['uid']), 'username' => $cur['username'], 'app_id' => $appId,
            'target' => $callback,
        ]);
        $sep = strpos($callback, '?') === false ? '?' : '&';
        $url = $callback . $sep . 'ticket=' . urlencode($ticket);
        if (!empty($extra)) {
            $url .= '&state=' . urlencode(rawurlencode(json_encode($extra, JSON_UNESCAPED_UNICODE)));
        }
        return ['ok' => true, 'ticket' => $ticket, 'redirect' => $url, 'app' => self::publicApp($app)];
    }

    /** 应用服务端用 ticket 换 token（服务端直连） */
    public static function exchange($ticket, $appId, $appSecret)
    {
        $app = self::auth($appId, $appSecret);
        if (!$app['ok']) return $app;
        $appRow = $app['app'];

        list($ok, $res) = SsoModel::consumeTicket($ticket, $appId);
        if (!$ok) return ['ok' => false, 'message' => $res];

        $uid = intval($res['uid']);
        $token = SsoModel::issueToken($uid, $res['username'], $appId, 'base');
        Logger::info('sso', '应用换取 Token 成功', ['uid' => $uid, 'app_id' => $appId]);
        return [
            'ok'      => true,
            'token'   => $token['token'],
            'expires_in' => $token['expires_in'],
            'uid'     => $uid,
            'username'=> $res['username'],
        ];
    }

    /** 用 token 拉取用户信息 */
    public static function userinfo($token, $appId = '')
    {
        list($ok, $msg, $row) = SsoModel::verifyToken($token, $appId);
        if (!$ok) return ['ok' => false, 'message' => $msg];
        $info = UserService::info(intval($row['uid']));
        if (!$info) return ['ok' => false, 'message' => '用户不存在或已在 UCenter 被删除'];
        // 精简下发字段
        return [
            'ok' => true,
            'user' => [
                'uid'       => $info['uid'],
                'username'  => $info['username'],
                'nickname'  => $info['nickname'],
                'avatar'    => $info['avatar'],
                'email'     => $info['email'],
                'mobile'    => $info['mobile'],
                'gender'    => $info['gender'],
                'birthday'  => $info['birthday'],
                'bio'       => $info['bio'],
                'status'    => $info['status'],
            ],
        ];
    }

    /** 单点退出 */
    public static function logout($token, $appId = '')
    {
        list($ok, $msg, $row) = SsoModel::verifyToken($token, $appId);
        if (!$ok) return ['ok' => false, 'message' => $msg];
        SsoModel::revokeToken($token);
        Logger::info('sso', '应用发起单点退出', ['uid' => intval($row['uid']), 'app_id' => $row['app_id']]);
        return ['ok' => true];
    }

    /** 认证中心全局退出：吊销该用户所有应用的 token + 全部会话 */
    public static function logoutGlobal($uid)
    {
        $n1 = SsoModel::revokeUser($uid);
        $n2 = UserSession::destroyAll($uid);
        Logger::info('sso', '全局单点退出', ['uid' => intval($uid), 'extra' => ['tokens' => $n1, 'sessions' => $n2]]);
        return ['ok' => true, 'tokens' => $n1, 'sessions' => $n2];
    }

    /** 应用鉴权（AppID + AppSecret） */
    public static function auth($appId, $appSecret)
    {
        if ($appId === '' || $appSecret === '') {
            return ['ok' => false, 'message' => '缺少 AppID 或 AppSecret'];
        }
        $app = AppModel::findByAppId($appId);
        if (!$app || !$app['status']) {
            return ['ok' => false, 'message' => '应用不存在或已被禁用'];
        }
        if (!hash_equals($app['app_secret'], $appSecret)) {
            return ['ok' => false, 'message' => 'AppSecret 不正确'];
        }
        return ['ok' => true, 'app' => $app];
    }

    /** 通信测试 */
    public static function testConnect($appId, $appSecret)
    {
        $a = self::auth($appId, $appSecret);
        if (!$a['ok']) return $a;
        $app = $a['app'];
        // 自检：签发一个 ticket 并立即消费，验证链路闭环
        $tk = SsoModel::issueTicket(0, '__self_test__', $appId, '');
        list($ok, $res) = SsoModel::consumeTicket($tk, $appId);
        return [
            'ok' => $ok,
            'message' => $ok ? '通信正常：鉴权通过，Ticket 签发/消费链路闭环' : '链路测试失败：' . $res,
            'data' => [
                'app_name'   => $app['name'],
                'status'     => $app['status'],
                'sso_enabled'=> $app['sso_enabled'],
                'callbacks'  => $app['callbacks'],
                'ticket_test'=> $ok,
                'server_time'=> date('Y-m-d H:i:s'),
            ],
        ];
    }

    public static function publicApp($app)
    {
        unset($app['app_secret'], $app['ip_whitelist']);
        return $app;
    }
}
