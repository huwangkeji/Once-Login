<?php
/**
 * 验证码模型（邮箱 / 短信）
 * 发送通道（邮件/短信）未配置时：仅 debug 模式（本地开发）回显验证码；
 * 生产模式（debug=false）拒绝发送且绝不回显/记录明文验证码，防止接口泄露导致账户接管。
 */
class VerifyModel
{
    public static function issue($target, $scene = 'findpwd', $ttl = 600)
    {
        $db   = Database::getInstance();
        $code = SmsSender::makeCode();
        $salt = rand_str(8);
        $db->insert('verify_codes', [
            'target'     => $target,
            'scene'      => $scene,
            'code_hash'  => hash('sha256', strtolower($code) . $salt),
            'salt'       => $salt,
            'ip'         => Request::ip(),
            'created_at' => time(),
            'expires_at' => time() + intval($ttl),
        ]);
        // 安全：日志不记录明文验证码，仅记录签发事实（避免验证码泄露到日志文件）
        Logger::info('system', "签发验证码 scene=$scene target=$target", ['target' => $target]);
        return $code;
    }

    /**
     * 校验验证码
     * @param bool $consume 是否用后即焚
     */
    public static function check($target, $scene, $code, $consume = true)
    {
        $db = Database::getInstance();
        $row = $db->one(
            "SELECT * FROM verify_codes WHERE target=? AND scene=? AND used=0 ORDER BY id DESC LIMIT 1",
            [$target, $scene]
        );
        if (!$row) return [false, '请先获取验证码'];
        if (intval($row['expires_at']) < time()) return [false, '验证码已过期'];
        if (intval($row['try_count']) >= 5) return [false, '尝试次数过多，请重新获取'];
        if (hash('sha256', strtolower(trim($code)) . $row['salt']) !== $row['code_hash']) {
            $db->update('verify_codes', ['try_count' => intval($row['try_count']) + 1], 'id=?', [$row['id']]);
            return [false, '验证码不正确'];
        }
        if ($consume) {
            $db->update('verify_codes', ['used' => 1], 'id=?', [$row['id']]);
        }
        return [true, 'ok'];
    }

    /** 发送（依据配置走邮件/短信；通道未配置时仅 debug 模式回显，生产环境拒绝发送） */
    public static function send($target, $scene, $code)
    {
        $type = Validator::email($target) ? 'email' : 'sms';
        Validator::reset();
        $sceneName = [
            'register'      => '注册',
            'findpwd'       => '找回密码',
            'admin_findpwd' => '管理员找回密码',
            'bind'          => '绑定',
            'login'         => '登录',
        ][$scene] ?? $scene;
        if ($type === 'email' && Config::get('mail_enabled')) {
            return self::sendMail($target, $sceneName, $code);
        }
        if ($type === 'sms' && Config::get('sms_enabled')) {
            return self::sendSms($target, $scene, $code);
        }
        // 发送通道未配置：
        //  - debug 模式：回显验证码，方便本地联调（受 Config::get('debug') 门控）
        //  - 生产模式：拒绝发送并告知配置缺失，绝不把验证码暴露给调用方
        if (Config::get('debug')) {
            $hint = $type === 'sms' ? '短信服务未开启' : '未配置邮件/短信服务';
            return [true, '调试模式：验证码为 ' . $code . '（' . $hint . '）'];
        }
        return [false, $type === 'sms'
            ? '发送通道未配置，请联系管理员接入短信服务'
            : '发送通道未配置，请联系管理员接入邮件服务'];
    }

    private static function sendMail($to, $scene, $code)
    {
        $host = Config::get('mail_host');
        $port = intval(Config::get('mail_port', 465));
        $user = Config::get('mail_user');
        $pass = Config::get('mail_pass');
        $from = Config::get('mail_from') ?: $user;
        $subject = '=?UTF-8?B?' . base64_encode('【' . site_name() . '】' . $scene . '验证码') . '?=';
        $body = "您的{$scene}验证码是：{$code}\n\n10 分钟内有效，请勿转发给他人。\n\n" . site_name();
        // 轻量 SMTP 实现（SSL）
        $errno = $errstr = null;
        $fp = @fsockopen(($port === 465 ? 'ssl://' : '') . $host, $port, $errno, $errstr, 10);
        if (!$fp) {
            return [false, 'SMTP 连接失败：' . $errstr];
        }
        $lines = [
            'EHLO ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'),
            'AUTH LOGIN',
            base64_encode($user),
            base64_encode($pass),
            'MAIL FROM: <' . $from . '>',
            'RCPT TO: <' . $to . '>',
            'DATA',
        ];
        $read = function () use ($fp) {
            $s = '';
            while ($line = fgets($fp, 512)) {
                $s .= $line;
                if (substr($line, 3, 1) === ' ' || $line === "\r\n") break;
            }
            return $s;
        };
        $read();
        $ok = true;
        foreach ($lines as $l) {
            fwrite($fp, $l . "\r\n");
            $r = $read();
            if (preg_match('/^[45]/', $r)) { $ok = false; break; }
        }
        if ($ok) {
            fwrite($fp, "Subject: $subject\r\nFrom: $from\r\nTo: $to\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n" . $body . "\r\n.\r\n");
            $r = $read();
            $ok = preg_match('/^2/', $r);
        }
        fwrite($fp, "QUIT\r\n");
        fclose($fp);
        return $ok ? [true, '邮件已发送'] : [false, '邮件发送失败，请检查 SMTP 配置'];
    }

    private static function sendSms($to, $scene, $code)
    {
        return SmsSender::send($to, $scene, $code);
    }
}

