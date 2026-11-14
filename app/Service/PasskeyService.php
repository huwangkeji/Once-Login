<?php
/**
 * 通行密钥（WebAuthn / Passkey）服务
 * 负责注册、认证、管理的业务逻辑
 *
 * 依赖：
 *   - app/Core/WebAuthn/  （lbuchs/webauthn 库）
 *   - app/Model/PasskeyModel.php
 *   - app/Core/UserSession.php
 *   - app/Service/UserService.php
 */
class PasskeyService
{
    // ==================== 配置解析 ====================

    /** 是否启用 */
    public static function enabled()
    {
        return (bool) Config::get('passkey_enabled', 0);
    }

    /** RP ID（域名），passkeys 永久绑定此域 */
    public static function rpId()
    {
        $cfg = (string) Config::get('passkey_rp_id', '');
        if ($cfg !== '') {
            return $cfg;
        }
        $url  = site_url();
        $host = parse_url($url, PHP_URL_HOST);
        return $host ?: '127.0.0.1';
    }

    /** RP 显示名称 */
    public static function rpName()
    {
        $name = (string) Config::get('passkey_rp_name', '');
        if ($name !== '') {
            return $name;
        }
        return (string) Config::get('site_name', '统一身份认证');
    }

    // ==================== 访问域可用性（WebAuthn 硬约束） ====================

    /**
     * 当前访问环境是否可用于通行密钥。
     *
     * 浏览器（Chrome/Edge/Safari）对 WebAuthn 有两条硬约束，任一不满足就会直接抛
     * SecurityError 并在界面提示 "This is an invalid domain." / "not a secure context"：
     *   ① RP ID 必须是「合法域名」——IP 字面量（127.0.0.1、192.168.x.x、[::1]）一律非法；
     *   ② 页面必须处于安全上下文——https，或 http://localhost。
     * 另外 RP ID 必须是当前访问域名本身或其上级域，否则同样被拒。
     *
     * @return array|null null = 可用；否则 ['code','message','hint']
     */
    public static function domainIssue()
    {
        $rpId = strtolower(trim(self::rpId()));
        $host = self::requestHost();

        if ($rpId === '') {
            return self::issue('rp_empty',
                '通行密钥域名（RP ID）未配置。',
                '请在后台「安全与验证 → 通行密钥」中填写本站使用的真实域名。');
        }

        // ① 访问地址本身是 IP 字面量（最常见：用 127.0.0.1 / 局域网 IP 打开）
        if ($host !== '' && self::isIpLiteral($host)) {
            return self::issue('ip',
                '当前通过 IP 地址（' . $host . '）访问，浏览器不允许在 IP 上使用通行密钥，'
                . '点「添加通行密钥」会直接提示 “This is an invalid domain.”。',
                '本机调试请改用 http://localhost 打开本站；线上请使用已配置 HTTPS 的真实域名。');
        }

        // ② 非安全上下文（http + 非 localhost）
        if ($host !== '' && !self::isHttps() && $host !== 'localhost') {
            return self::issue('insecure',
                '当前页面不是 HTTPS 安全环境，浏览器会拒绝通行密钥操作。',
                '请为该域名配置 HTTPS 后访问；本机调试可使用 http://localhost。');
        }

        // ③ 单标签内网主机名（localhost 除外）
        if ($host !== '' && $host !== 'localhost' && strpos($host, '.') === false) {
            return self::issue('host',
                '当前主机名「' . $host . '」不是浏览器认可的合法域名，无法为其创建通行密钥。',
                '请改用 http://localhost 或已解析的正式域名访问。');
        }

        // ④ RP ID 自身就是 IP（多为配置写错）
        if (self::isIpLiteral($rpId)) {
            return self::issue('ip_rpid',
                '通行密钥 RP ID 被配置为 IP 地址（' . $rpId . '），浏览器不接受。',
                '请在后台把「通行密钥 RP ID」改为 localhost 或本站真实域名。');
        }

        // ⑤ 当前访问域名与 RP ID 不匹配（RP ID 必须是当前域名或其上级域）
        if ($host !== '' && !self::isIpLiteral($host)
            && $host !== $rpId && substr($host, -strlen('.' . $rpId)) !== '.' . $rpId) {
            return self::issue('mismatch',
                '当前访问域名（' . $host . '）与通行密钥 RP ID（' . $rpId . '）不一致。',
                '请在后台把「通行密钥 RP ID」改为 ' . $host . '，或改从 ' . $rpId . ' 访问。');
        }

        return null;
    }

    /** 当前访问环境是否可用于通行密钥 */
    public static function domainUsable()
    {
        return self::domainIssue() === null;
    }

    /** 问题描述（供 API / 页面直接使用） */
    private static function issue($code, $message, $hint)
    {
        return ['code' => $code, 'message' => $message, 'hint' => $hint];
    }

    /** 浏览器实际访问的主机名（去掉端口；CLI 场景返回空串） */
    private static function requestHost()
    {
        $host = (string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? '');
        $host = strtolower(trim(explode(',', $host)[0]));
        return preg_replace('/:\d+$/', '', $host);
    }

    /** 是否 IP 字面量（含 IPv6 的 [::1] 写法） */
    private static function isIpLiteral($host)
    {
        if ($host === '') {
            return false;
        }
        if ($host[0] === '[' || strpos($host, ':') !== false) {
            return true;   // IPv6
        }
        return (bool) filter_var($host, FILTER_VALIDATE_IP);
    }

    /** 当前请求是否为 HTTPS（兼容反向代理的 X-Forwarded-Proto） */
    private static function isHttps()
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (intval($_SERVER['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }
        $proto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
        return $proto !== '' && explode(',', $proto)[0] === 'https';
    }

    /** 超时秒数 */
    public static function timeout()
    {
        return (int) Config::get('passkey_timeout', 120);
    }

    /** UV 要求：required / preferred / discouraged */
    public static function uvRequirement()
    {
        $uv = strtolower((string) Config::get('passkey_uv', 'preferred'));
        return in_array($uv, ['required', 'preferred', 'discouraged'], true) ? $uv : 'preferred';
    }

    /** 每用户最大密钥数 */
    public static function maxPerUser()
    {
        return max(1, (int) Config::get('passkey_max_per_user', 10));
    }

    // ==================== WebAuthn 实例 ====================

    /** 创建 WebAuthn 库实例（base64url 编码，所有 attestation 格式） */
    private static function instance()
    {
        return new \lbuchs\WebAuthn\WebAuthn(
            self::rpName(),
            self::rpId(),
            null,   // 允许所有 attestation 格式
            true    // 使用 base64url 编码
        );
    }

    /** 用户句柄（稳定、不透明、≤64 字节），用于 discoverable credential */
    private static function userHandle($uid)
    {
        return substr(hash('sha256', 'uic|passkey|' . intval($uid), true), 0, 32);
    }

    /** base64url → 二进制 */
    private static function b64u2bin($b64u)
    {
        return \lbuchs\WebAuthn\Binary\ByteBuffer::fromBase64Url($b64u)->getBinaryString();
    }

    // ==================== 注册 ====================

    /**
     * 生成注册选项（已登录用户添加新通行密钥）
     * @return array {ok, tx, options}
     */
    public static function registerOptions($uid)
    {
        if (!self::enabled()) {
            return self::err('通行密钥功能未启用');
        }
        if (($issue = self::domainIssue())) {
            return self::err($issue['message']);
        }
        $uid = intval($uid);
        if ($uid <= 0) {
            return self::err('用户未登录');
        }

        PasskeyModel::ensureSchema();

        $info = UserService::info($uid);
        if (!$info) {
            return self::err('用户信息获取失败');
        }

        $count = PasskeyModel::countByUid($uid);
        $max   = self::maxPerUser();
        if ($count >= $max) {
            return self::err('已达通行密钥数量上限（' . $max . ' 个）');
        }

        $wa         = self::instance();
        $excludeIds = PasskeyModel::credentialIdsByUid($uid);
        $uv         = self::uvRequirement();

        $args = $wa->getCreateArgs(
            self::userHandle($uid),
            $info['username'],
            $info['nickname'] ?: $info['username'],
            self::timeout(),
            'required',   // residentKey=required → discoverable credential
            $uv,          // userVerification
            null,         // 任意附件类型（platform + cross-platform）
            $excludeIds
        );
        // 消费端应用：attestation=none（隐私优先，不收集设备证明）
        $args->publicKey->attestation = 'none';

        $challengeBin = $wa->getChallenge()->getBinaryString();
        $tx           = PasskeyModel::issueChallenge('reg', $uid, $challengeBin);

        return [
            'ok'      => true,
            'tx'      => $tx,
            'options' => $args,
        ];
    }

    /**
     * 完成注册——验证 attestation 并存储公钥
     * @param int    $uid
     * @param string $tx             事务令牌
     * @param string $clientDataB64  base64url(clientDataJSON)
     * @param string $attestationB64 base64url(attestationObject)
     * @param string $label          用户自定义标签
     * @return array
     */
    public static function registerFinish($uid, $tx, $clientDataB64, $attestationB64, $label = '')
    {
        if (!self::enabled()) {
            return self::err('通行密钥功能未启用');
        }
        $uid = intval($uid);
        if ($uid <= 0) {
            return self::err('用户未登录');
        }

        $challenge = PasskeyModel::consumeChallenge($tx);
        if (!$challenge) {
            return self::err('注册会话已过期，请重新操作');
        }
        if ($challenge['type'] !== 'reg') {
            return self::err('会话类型不匹配');
        }
        if (intval($challenge['uid']) !== $uid) {
            return self::err('用户不匹配');
        }

        $wa          = self::instance();
        $clientData  = self::b64u2bin($clientDataB64);
        $attestation = self::b64u2bin($attestationB64);

        try {
            $data = $wa->processCreate(
                $clientData,
                $attestation,
                $challenge['challenge'],
                self::uvRequirement() === 'required',
                true,    // requireUserPresent
                false,   // failIfRootMismatch（无根证书，不校验）
                false    // requireCtsProfileMatch
            );
        } catch (\lbuchs\WebAuthn\WebAuthnException $e) {
            return self::err('通行密钥注册失败：' . $e->getMessage());
        }

        $credId = $data->credentialId; // binary string

        // 防重复注册
        if (PasskeyModel::findByCredentialId($credId)) {
            return self::err('该通行密钥已注册');
        }

        if (PasskeyModel::countByUid($uid) >= self::maxPerUser()) {
            return self::err('已达通行密钥数量上限');
        }

        PasskeyModel::save([
            'uid'            => $uid,
            'credential_id'  => $credId,
            'public_key'     => $data->credentialPublicKey,
            'sign_count'     => $data->signatureCounter ?? 0,
            'transports'     => '',
            'aaguid'         => $data->AAGUID ? bin2hex($data->AAGUID) : '',
            'label'          => mb_substr(trim($label), 0, 60),
            'backup_eligible' => $data->isBackupEligible ? 1 : 0,
            'backup_state'   => $data->isBackedUp ? 1 : 0,
        ]);

        Logger::info('passkey', '通行密钥注册成功', ['uid' => $uid]);

        return ['ok' => true];
    }

    // ==================== 认证 ====================

    /**
     * 生成认证选项
     * @param int $uid  >0 时绑定指定用户的 credential（已登录快速认证）；=0 时发现式（无用户名登录）
     * @return array {ok, tx, options}
     */
    public static function authOptions($uid = 0)
    {
        if (!self::enabled()) {
            return self::err('通行密钥功能未启用');
        }
        if (($issue = self::domainIssue())) {
            return self::err($issue['message']);
        }
        PasskeyModel::ensureSchema();

        $wa     = self::instance();
        $uv     = self::uvRequirement();
        $credIds = [];
        if (intval($uid) > 0) {
            $credIds = PasskeyModel::credentialIdsByUid(intval($uid));
        }

        $args = $wa->getGetArgs(
            $credIds,
            self::timeout(),
            true, true, true, true, true, // 允许所有 transport
            $uv
        );

        $challengeBin = $wa->getChallenge()->getBinaryString();
        $tx           = PasskeyModel::issueChallenge('auth', intval($uid), $challengeBin);

        return [
            'ok'      => true,
            'tx'      => $tx,
            'options' => $args,
        ];
    }

    /**
     * 完成认证——验证签名并创建会话
     * @param string $tx
     * @param string $credIdB64     base64url(credentialId)
     * @param string $clientDataB64 base64url(clientDataJSON)
     * @param string $authDataB64   base64url(authenticatorData)
     * @param string $sigB64        base64url(signature)
     * @param bool   $remember
     * @param string $appId
     * @return array
     */
    public static function authFinish($tx, $credIdB64, $clientDataB64, $authDataB64, $sigB64, $remember = false, $appId = '')
    {
        if (!self::enabled()) {
            return self::err('通行密钥功能未启用');
        }

        $challenge = PasskeyModel::consumeChallenge($tx);
        if (!$challenge) {
            return self::err('认证会话已过期，请重新操作');
        }
        if ($challenge['type'] !== 'auth') {
            return self::err('会话类型不匹配');
        }

        $credId  = self::b64u2bin($credIdB64);
        $passkey = PasskeyModel::findByCredentialId($credId);
        if (!$passkey) {
            return self::err('通行密钥未识别');
        }

        $uid = intval($passkey['uid']);

        // 若 challenge 绑定了 uid（已登录场景），校验一致性
        if (intval($challenge['uid']) > 0 && intval($challenge['uid']) !== $uid) {
            return self::err('通行密钥与当前用户不匹配');
        }

        $wa         = self::instance();
        $clientData = self::b64u2bin($clientDataB64);
        $authData   = self::b64u2bin($authDataB64);
        $signature  = self::b64u2bin($sigB64);

        try {
            $ok = $wa->processGet(
                $clientData,
                $authData,
                $signature,
                $passkey['public_key'],
                $challenge['challenge'],
                intval($passkey['sign_count']),
                self::uvRequirement() === 'required',
                true    // requireUserPresent
            );
        } catch (\lbuchs\WebAuthn\WebAuthnException $e) {
            return self::err('通行密钥认证失败：' . $e->getMessage());
        }

        if (!$ok) {
            return self::err('通行密钥签名验证失败');
        }

        // 更新签名计数（克隆检测）
        $newCount = $wa->getSignatureCounter();
        if ($newCount !== null) {
            PasskeyModel::updateSignCount($passkey['id'], $newCount);
        }

        // 获取用户信息并创建会话
        $info = UserService::info($uid);
        if (!$info) {
            return self::err('用户信息获取失败');
        }

        $username = $info['username'];
        $sid      = UserSession::create($uid, $username, $remember, $appId, $appId ? 'app' : 'center');

        Logger::info('passkey', '通行密钥登录成功', [
            'uid'      => $uid,
            'username' => $username,
            'app_id'   => $appId,
            'extra'    => ['remember' => $remember, 'ua' => UserSession::deviceLabel()],
        ]);

        return ['ok' => true, 'uid' => $uid, 'username' => $username, 'sid' => $sid];
    }

    // ==================== 管理 ====================

    /** 列出用户的通行密钥（不含敏感数据） */
    public static function list($uid)
    {
        PasskeyModel::ensureSchema();
        $rows   = PasskeyModel::listByUid(intval($uid));
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'id'             => intval($row['id']),
                'label'          => $row['label'],
                'aaguid'         => $row['aaguid'],
                'sign_count'     => intval($row['sign_count']),
                'backup_eligible' => intval($row['backup_eligible']),
                'backup_state'   => intval($row['backup_state']),
                'created_at'     => intval($row['created_at']),
                'last_used_at'   => intval($row['last_used_at']),
                'created_text'   => human_time($row['created_at']),
                'last_used_text' => $row['last_used_at'] ? human_time($row['last_used_at']) : '从未使用',
            ];
        }
        return $result;
    }

    /** 重命名通行密钥 */
    public static function rename($uid, $id, $label)
    {
        $label = mb_substr(trim($label), 0, 60);
        $ok    = PasskeyModel::rename(intval($id), intval($uid), $label);
        return $ok ? ['ok' => true] : self::err('通行密钥不存在或无权操作');
    }

    /** 删除通行密钥 */
    public static function delete($uid, $id)
    {
        $ok = PasskeyModel::delete(intval($id), intval($uid));
        if ($ok) {
            Logger::info('passkey', '通行密钥删除', ['uid' => intval($uid), 'id' => intval($id)]);
        }
        return $ok ? ['ok' => true] : self::err('通行密钥不存在或无权操作');
    }

    /** 统计用户通行密钥数 */
    public static function count($uid)
    {
        PasskeyModel::ensureSchema();
        return PasskeyModel::countByUid(intval($uid));
    }

    // ==================== 内部 ====================

    private static function err($msg, $code = 1)
    {
        return ['ok' => false, 'message' => $msg, 'code' => $code];
    }
}
