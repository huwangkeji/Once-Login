<?php
/**
 * 管理员找回密码：邮件验证码自助重置
 *
 * 后台账号权限极高，策略比前台「找回密码」更严：
 *  1) 三重前置：功能开关 admin_findpwd_enabled + 邮件服务已开启 + 该管理员已设置邮箱
 *  2) 账号与邮箱必须同时匹配；不匹配统一回「账号或邮箱不匹配」，不泄露账号是否存在
 *  3) 验证码默认绝不回显（防后台被接管）；仅当 debug 与 admin_findpwd_debug 同时开启才回显
 *  4) 频控（账号 60s / 1 小时 5 次，IP 1 小时 20 次）+ 图形验证码 + IP 访问策略（由 API 层把关）
 *  5) 全程写审计日志；重置成功后递增会话版本号，其它设备已登录的后台立即失效
 */
class AdminResetService
{
    const SCENE = 'admin_findpwd';

    /**
     * 找回密码是否可用：[bool 可用, string 不可用原因]
     * 页面据此降级为「请联系超级管理员重置」，而不是放出无法提交的表单。
     */
    public static function status()
    {
        if (!intval(Config::get('admin_findpwd_enabled', 1))) {
            return [false, '管理员自助找回密码功能已关闭'];
        }
        if (!self::channelReady()) {
            return [false, '站点未开启邮件服务'];
        }
        if (!AdminModel::ensureEmailColumn()) {
            return [false, '管理员表缺少邮箱字段且自动升级失败'];
        }
        $n = intval(Database::getInstance()->val("SELECT COUNT(*) FROM admins WHERE status=1 AND email<>''"));
        if ($n === 0) {
            return [false, '暂无管理员设置邮箱'];
        }
        return [true, ''];
    }

    /**
     * 验证码通道是否就绪：邮件服务已开启，或处于本地联调（debug + admin_findpwd_debug 双开）
     * 双开关的意义：即便站点误开 debug，也不会顺带把「后台管理员」的验证码暴露出去。
     */
    private static function channelReady()
    {
        return intval(Config::get('mail_enabled', 0)) > 0 || self::devEcho();
    }

    private static function devEcho()
    {
        return (bool)Config::get('debug') && intval(Config::get('admin_findpwd_debug', 0)) > 0;
    }

    /** 降级提示里的对外联系邮箱 */
    public static function contactEmail()
    {
        return (string)Config::get('site_contact_email', '');
    }

    /** 第一步：向管理员邮箱发送验证码 */
    public static function sendCode($username, $email)
    {
        if (!intval(Config::get('admin_findpwd_enabled', 1))) {
            return self::err('管理员自助找回密码功能已关闭');
        }
        if (!self::channelReady()) {
            return self::err('站点未开启邮件服务，无法发送验证码');
        }
        AdminModel::ensureEmailColumn();

        $username = trim((string)$username);
        $email    = trim((string)$email);
        if ($username === '' || $email === '') {
            return self::err('请输入管理员账号与邮箱');
        }
        Validator::reset();
        if (!Validator::email($email)) {
            return self::err('请输入正确的邮箱地址');
        }
        Validator::reset();

        $row = self::locate($username, $email);
        if ($row === null) {
            // 统一措辞：不区分「账号不存在」与「邮箱不匹配」，避免账号枚举
            Logger::warn('admin', '管理员找回密码：账号或邮箱不匹配', [
                'target' => $username, 'extra' => ['ip' => Request::ip()],
            ]);
            return self::err('账号或邮箱不匹配');
        }

        list($ok, $msg) = self::rateLimit($email);
        if (!$ok) {
            return self::err($msg);
        }

        $code = VerifyModel::issue($email, self::SCENE, 600);
        list($sent, $sendMsg) = VerifyModel::send($email, self::SCENE, $code);
        if (!$sent) {
            // 发送失败不保留已签发的验证码，避免绕过频控反复重试
            Database::getInstance()->delete('verify_codes', 'target=? AND scene=? AND used=0', [$email, self::SCENE]);
            return self::err($sendMsg);
        }

        Logger::info('admin', '管理员找回密码：验证码已发送(id=' . $row['id'] . ')', [
            'admin_id' => intval($row['id']), 'username' => $row['username'], 'extra' => ['ip' => Request::ip()],
        ]);

        $ret = ['ok' => true, 'message' => '验证码已发送至该管理员预留邮箱'];
        // 管理员场景默认绝不回显验证码；仅 debug + admin_findpwd_debug 双开时回显，供本地联调
        if (self::devEcho()) {
            $ret['debug_code'] = $code;
        }
        // 未真正走邮件通道时如实回传 VerifyModel 的提示（「调试模式：…」），避免谎称已发送
        if (!intval(Config::get('mail_enabled', 0))) {
            $ret['message'] = $sendMsg;
        }
        return $ret;
    }

    /** 第二步：校验验证码并重置密码 */
    public static function reset($username, $email, $code, $newPassword, $confirm)
    {
        if (!intval(Config::get('admin_findpwd_enabled', 1))) {
            return self::err('管理员自助找回密码功能已关闭');
        }
        if ($newPassword !== $confirm) {
            return self::err('两次输入的新密码不一致');
        }
        // 管理员口令强度：不弱于 8 位，且必须同时含字母与数字（与后台改密同一策略）
        $minLen = max(8, intval(Config::get('security.pwd_min_length', 8)));
        $maxLen = intval(Config::get('security.pwd_max_length', 32));
        if (strlen($newPassword) < $minLen || strlen($newPassword) > $maxLen) {
            return self::err("新密码长度需为 {$minLen}-{$maxLen} 位");
        }
        if (!preg_match('/[A-Za-z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword)) {
            return self::err('新密码必须同时包含字母和数字');
        }

        AdminModel::ensureEmailColumn();
        $username = trim((string)$username);
        $email    = trim((string)$email);
        $row      = self::locate($username, $email);
        if ($row === null) {
            return self::err('账号或邮箱不匹配');
        }

        list($ok, $msg) = VerifyModel::check($email, self::SCENE, $code);
        if (!$ok) {
            Logger::warn('admin', '管理员找回密码：验证码校验失败(id=' . $row['id'] . ')', [
                'admin_id' => intval($row['id']), 'username' => $row['username'], 'extra' => ['reason' => $msg],
            ]);
            return self::err($msg);
        }

        // 历史密码防重用（与后台改密一致的保留 5 次策略）
        $history = (array)json_decode((string)Config::get('admin_pwd_history_' . $row['id'], '[]'), true);
        foreach (array_slice($history, 0, 5) as $h) {
            if (password_verify($newPassword, (string)$h)) {
                return self::err('新密码与最近使用过的密码重复，请更换');
            }
        }

        list($ok2, $msg2) = AdminModel::resetPassword($row['id'], $newPassword);
        if (!$ok2) {
            return self::err($msg2);
        }
        $history = array_merge([password_hash($newPassword, PASSWORD_DEFAULT)], array_slice($history, 0, 4));
        Config::set('admin_pwd_history_' . $row['id'], $history, 'admin_pwd', '历史密码（防重用）');
        Config::set('admin_pwd_changed_at_' . $row['id'], time(), 'admin_pwd', '最近改密时间（密码有效期策略）');

        Logger::info('admin', '管理员通过邮箱验证码重置密码成功(id=' . $row['id'] . ')，其它设备后台登录已失效', [
            'admin_id' => intval($row['id']), 'username' => $row['username'], 'extra' => ['ip' => Request::ip()],
        ]);
        return ['ok' => true, 'message' => '密码已重置，请使用新密码登录', 'relogin' => true];
    }

    /** 账号 + 邮箱双重匹配；命中返回管理员行，否则 null */
    private static function locate($username, $email)
    {
        $row = AdminModel::findByName($username);
        if (!$row) {
            return null;
        }
        if (!intval($row['status'])) {
            return null;
        }
        $bound = strtolower(trim((string)($row['email'] ?? '')));
        if ($bound === '' || $bound !== strtolower($email)) {
            return null;
        }
        return $row;
    }

    /** 发送频控：同一邮箱 60 秒 1 次 / 1 小时 5 次；同一 IP 1 小时 20 次 */
    private static function rateLimit($email)
    {
        $db  = Database::getInstance();
        $now = time();
        $last = intval($db->val(
            "SELECT MAX(created_at) FROM verify_codes WHERE target=? AND scene=?",
            [$email, self::SCENE]
        ));
        if ($last > 0 && $now - $last < 60) {
            return [false, '发送过于频繁，请 ' . (60 - ($now - $last)) . ' 秒后再试'];
        }
        $cnt = intval($db->val(
            "SELECT COUNT(*) FROM verify_codes WHERE target=? AND scene=? AND created_at>?",
            [$email, self::SCENE, $now - 3600]
        ));
        if ($cnt >= 5) {
            return [false, '该邮箱发送次数过多，请 1 小时后再试'];
        }
        $ipCnt = intval($db->val(
            "SELECT COUNT(*) FROM verify_codes WHERE scene=? AND ip=? AND created_at>?",
            [self::SCENE, Request::ip(), $now - 3600]
        ));
        if ($ipCnt >= 20) {
            return [false, '当前网络请求过于频繁，请稍后再试'];
        }
        return [true, ''];
    }

    private static function err($msg, $data = null)
    {
        return ['ok' => false, 'message' => $msg, 'data' => $data];
    }
}
