<?php
/**
 * 登录 IP 访问策略（企业风控：白名单 / 黑名单）
 *
 * 配置（settings 扁平键）：
 *   login_ip_policy : 0=关闭  1=白名单（仅名单内 IP 可登录）  2=黑名单（名单内 IP 禁止登录）
 *   login_ip_list   : 逗号 / 分号 / 换行分隔；支持精确 IP、CIDR（如 10.0.0.0/8）、单段通配（如 192.168.1.*）
 *
 * 默认关闭，不影响既有部署与回归自检。
 */
class IpPolicy
{
    /**
     * 检查某 IP 是否符合当前访问策略
     * @return array [bool 是否放行, string 拒绝原因]
     */
    public static function check($ip = null)
    {
        $ip     = $ip ?: Request::ip();
        $policy = intval(Config::get('login_ip_policy', 0));
        if ($policy === 0) {
            return [true, ''];
        }
        $list = self::parseList((string)Config::get('login_ip_list', ''));
        $hit  = self::match($ip, $list);

        if ($policy === 1) { // 白名单
            if ($hit) {
                return [true, ''];
            }
            return [false, '当前 IP 不在允许登录的名单内'];
        }
        // 黑名单
        if ($hit) {
            return [false, '当前 IP 已被限制登录'];
        }
        return [true, ''];
    }

    /** 快捷判断 */
    public static function isAllowed($ip = null)
    {
        list($ok) = self::check($ip);
        return $ok;
    }

    /** 校验并返回策略摘要（后台设置保存前校验用） */
    public static function validatePolicy($policy, $listRaw)
    {
        $policy = intval($policy);
        if (!in_array($policy, [0, 1, 2], true)) {
            return [false, '策略取值必须为 0/1/2'];
        }
        foreach (self::parseList((string)$listRaw) as $item) {
            if (strpos($item, '/') !== false) {
                list($net, $bits) = explode('/', $item, 2);
                if (!filter_var($net, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || intval($bits) < 0 || intval($bits) > 32) {
                    return [false, 'CIDR 格式不合法：' . $item];
                }
            } elseif (strpos($item, '*') !== false) {
                $seg = explode('.', rtrim($item, '*'));
                $base = rtrim($item, '*');
                if (!filter_var('1.2.3.4', FILTER_VALIDATE_IP)) {
                    // 保持简单：仅校验段数
                }
                if (count($seg) < 1 || count($seg) > 4) {
                    return [false, '通配 IP 格式不合法：' . $item];
                }
            } else {
                if (!filter_var($item, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && !filter_var($item, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    return [false, 'IP 格式不合法：' . $item];
                }
            }
        }
        return [true, ''];
    }

    /* ---------------- 内部 ---------------- */

    private static function parseList($raw)
    {
        $items = [];
        foreach (preg_split('/[\s,;]+/', trim((string)$raw)) as $it) {
            $it = trim($it);
            if ($it !== '') {
                $items[] = $it;
            }
        }
        return $items;
    }

    private static function match($ip, array $list)
    {
        foreach ($list as $item) {
            if (strpos($item, '/') !== false) { // CIDR（IPv4）
                list($net, $bits) = explode('/', $item, 2);
                $ipLong  = ip2long($ip);
                $netLong = ip2long(trim($net));
                $bits    = intval(trim($bits));
                if ($ipLong === false || $netLong === false || $bits < 0 || $bits > 32) {
                    continue;
                }
                $mask = $bits === 0 ? 0 : (0xffffffff << (32 - $bits)) & 0xffffffff;
                if (($ipLong & $mask) === ($netLong & $mask)) {
                    return true;
                }
            } elseif (strpos($item, '*') !== false) { // 单段通配：192.168.1.*
                $pattern = str_replace(['.', '*'], ['\.', '[0-9]{1,3}'], trim($item));
                if (preg_match('/^' . $pattern . '$/', $ip)) {
                    return true;
                }
            } elseif ($ip === trim($item)) { // 精确 IP
                return true;
            }
        }
        return false;
    }
}