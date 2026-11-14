<?php
/**
 * 短信网关（统一门面 / 核心发送逻辑）
 *
 * 职责边界：
 *   - 只依赖 SmsProviderInterface，不认识任何具体服务商 → 新增服务商零改动
 *   - 公共配置（有效期、频率限制、模板变量、调度策略）由本类统一处理
 *   - 服务商私有配置（密钥、接口地址、签名、模板、回调）由各 Provider 自声明
 *
 * 调度策略 sms_strategy：
 *   priority   指定主服务商优先（sms_provider），失败后按优先级依次降级
 *   roundrobin 轮转起点，失败后依次尝试
 *   failover   按 priority 升序依次尝试（默认）
 */
class SmsGateway
{
    /** 公共配置字段定义（与服务商无关） */
    public static function commonFields()
    {
        return [
            ['key' => 'code_ttl',       'label' => '验证码有效期（秒）', 'type' => 'text',     'default' => 600,  'help' => '验证码自签发起的存活时间'],
            ['key' => 'send_interval',  'label' => '同号发送间隔（秒）', 'type' => 'text',     'default' => 60,   'help' => '同一手机号两次发送的最小间隔，防刷'],
            ['key' => 'daily_limit',    'label' => '同号每日上限',       'type' => 'text',     'default' => 10,   'help' => '同一手机号自然日内最多发送条数'],
            ['key' => 'ip_daily_limit', 'label' => '同 IP 每日上限',     'type' => 'text',     'default' => 30,   'help' => '同一 IP 自然日内最多发送条数'],
            ['key' => 'template_var',   'label' => '模板变量名',         'type' => 'text',     'default' => 'code','help' => '模板中的变量名，如 code；腾讯云按位置参数传递'],
            ['key' => 'strategy',       'label' => '调度策略',           'type' => 'select',   'default' => 'failover',
             'options' => ['failover' => '故障自动切换（默认）', 'priority' => '优先级', 'roundrobin' => '轮询'],
             'help' => '多服务商并存时的选择顺序；任一策略在单个服务商失败后都会自动尝试下一个'],
        ];
    }

    /** 解析公共配置（settings.sms_common JSON → 旧扁平键 → 默认值） */
    public static function commonConfig()
    {
        $raw = Config::get('sms_common', []);
        if (is_string($raw)) {
            $d = json_decode($raw, true);
            $raw = is_array($d) ? $d : [];
        }
        if (!is_array($raw)) {
            $raw = [];
        }
        $out = [];
        foreach (self::commonFields() as $f) {
            $k = $f['key'];
            $v = isset($raw[$k]) ? $raw[$k] : null;
            if ($v === null || $v === '') {
                $v = $f['default'] ?? '';
            }
            $out[$k] = is_scalar($v) ? (string)$v : $v;
        }
        // 兼容旧键
        $style = (string)Config::get('sms_code_style', 'digits');
        $out['code_style'] = in_array($style, ['digits', 'letters', 'mixed'], true) ? $style : 'digits';
        $out['provider']   = (string)Config::get('sms_provider', '');
        $out['enabled']    = (bool)Config::get('sms_enabled', false);
        return $out;
    }

    /** 生成验证码内容串（按公共配置的样式） */
    public static function makeCode()
    {
        $c     = self::commonConfig();
        $style = $c['code_style'];
        if ($style === 'digits') {
            $chars = '0123456789';
            $len   = 6;
        } else {
            // 去掉易混淆的 0O1I
            $chars = $style === 'letters'
                ? 'ABCDEFGHJKLMNPQRSTUVWXYZ'
                : '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
            $len   = 4;
        }
        $code = '';
        for ($i = 0; $i < $len; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $code;
    }

    /** 验证码有效期（秒） */
    public static function ttl()
    {
        $c = self::commonConfig();
        $t = intval($c['code_ttl']);
        return $t > 0 ? $t : 600;
    }

    /**
     * 发送频率限制
     * @return array [bool, string]
     */
    public static function throttle($mobile)
    {
        $c   = self::commonConfig();
        $db  = Database::getInstance();
        $now = time();
        $day = strtotime('today');

        $interval = intval($c['send_interval']);
        if ($interval > 0) {
            $last = $db->val("SELECT MAX(created_at) FROM verify_codes WHERE target=?", [$mobile]);
            if ($last) {
                $passed = $now - intval($last);
                if ($passed < $interval) {
                    return [false, '发送过于频繁，请 ' . ($interval - $passed) . ' 秒后再试'];
                }
            }
        }

        $dayLimit = intval($c['daily_limit']);
        if ($dayLimit > 0) {
            $cnt = intval($db->val("SELECT COUNT(*) FROM verify_codes WHERE target=? AND created_at>?", [$mobile, $day]));
            if ($cnt >= $dayLimit) {
                return [false, '该手机号今日发送次数已达上限，请明日再试'];
            }
        }

        $ipLimit = intval($c['ip_daily_limit']);
        if ($ipLimit > 0) {
            $ip  = Request::ip();
            $cnt = intval($db->val("SELECT COUNT(*) FROM verify_codes WHERE ip=? AND created_at>?", [$ip, $day]));
            if ($cnt >= $ipLimit) {
                return [false, '当前网络今日发送次数已达上限，请稍后再试'];
            }
        }
        return [true, ''];
    }

    /**
     * 发送短信验证码（核心入口）
     * @return array [bool, string]
     */
    public static function send($mobile, $scene, $code)
    {
        if (!preg_match('/^1[3-9]\d{9}$/', $mobile)) {
            return [false, '手机号格式不正确'];
        }

        list($ok, $msg) = self::throttle($mobile);
        if (!$ok) {
            return [false, $msg];
        }

        $c       = self::commonConfig();
        $varName = ($c['template_var'] ?? '') !== '' ? $c['template_var'] : 'code';
        $vars    = [$varName => $code];

        $available = SmsProviderRegistry::available();
        if (!$available) {
            return [false, '无可用的短信服务商：请先在「短信服务」中启用至少一个服务商并填写完整凭据'];
        }

        $strategy = ($c['strategy'] ?? 'failover');
        if ($strategy === 'roundrobin') {
            $ordered = self::roundRobin($available);
        } elseif ($strategy === 'priority') {
            $ordered = self::sortByPrimary($available, $c['provider']);
        } else {
            $ordered = $available; // 已按 priority 升序
        }

        $errors = [];
        foreach ($ordered as $p) {
            $r = $p['instance']->send($mobile, $scene, $code, $vars, $p['config']);
            if ($r[0]) {
                Logger::info('sms', '短信发送成功', [
                    'target' => $mobile,
                    'extra'  => ['provider' => $p['key'], 'scene' => $scene, 'strategy' => $strategy],
                ]);
                return [true, $r[1] . '（服务商：' . $p['name'] . '）'];
            }
            $errors[] = $p['name'] . '：' . $r[1];
            Logger::error('sms', '短信发送失败: ' . $p['key'], [
                'target' => $mobile,
                'extra'  => ['message' => $r[1]],
            ]);
            // 任一策略下都继续尝试下一个服务商（故障自动切换）
        }
        return [false, '短信发送失败：' . implode(' | ', $errors)];
    }

    /** 轮询：以当日已发送条数为起点轮转，无需额外存储 */
    private static function roundRobin($list)
    {
        $n = count($list);
        if ($n <= 1) {
            return $list;
        }
        try {
            $cnt = intval(Database::getInstance()->val(
                "SELECT COUNT(*) FROM verify_codes WHERE created_at>?", [strtotime('today')]
            ));
        } catch (Exception $e) {
            $cnt = 0;
        }
        $idx = $cnt % $n;
        return array_merge(array_slice($list, $idx), array_slice($list, 0, $idx));
    }

    /** 指定主服务商置顶，其余保持优先级顺序 */
    private static function sortByPrimary($list, $primary)
    {
        if ($primary === '') {
            return $list;
        }
        $head = [];
        $tail = [];
        foreach ($list as $p) {
            if ($p['key'] === $primary) {
                $head[] = $p;
            } else {
                $tail[] = $p;
            }
        }
        return array_merge($head, $tail);
    }

    /** 体检：逐个检查已注册服务商的配置状态 */
    public static function diagnose()
    {
        $out = [];
        foreach (SmsProviderRegistry::all() as $k => $cls) {
            $cfg = $cls::resolveConfig();
            $out[$k] = [
                'name'       => $cls::name(),
                'enabled'    => !empty($cfg['enabled']),
                'configured' => $cls::isConfigured(),
                'priority'   => intval($cfg['priority'] ?? 50),
                'masked'     => $cls::maskConfig(),
            ];
        }
        return $out;
    }
}
