<?php
/**
 * UCenter 门面：对上层业务提供统一调用入口
 * 所有调用自动记录到 uc_calls 与 logs(ucenter)
 */
class UCenterClient
{
    /** @var UCenterDriver */
    private $driver;
    private $cfg;
    private $enabled = true;

    public function __construct()
    {
        $this->cfg = [
            'driver'  => Config::get('uc_driver', 'http'),
            'url'     => Config::get('uc_url', ''),
            'ip'      => Config::get('uc_ip', ''),
            'key'     => Config::get('uc_key', ''),
            'appid'   => Config::get('uc_appid', 1),
            'timeout' => Config::get('uc_timeout', 10),
        ];
        $this->enabled = (bool)Config::get('uc_enabled', true);
        $this->driver = $this->cfg['driver'] === 'mock'
            ? new MockDriver($this->cfg)
            : new HttpDriver($this->cfg);
    }

    public function getDriver()
    {
        return $this->driver;
    }

    public function getConfig()
    {
        $c = $this->cfg;
        if ($c['key'] !== '') {
            $c['key'] = substr($c['key'], 0, 3) . '****' . substr($c['key'], -3);
        }
        return $c;
    }

    /** 底层调用，带日志 */
    public function call($module, $action, array $args = [], $log = true)
    {
        $t = microtime(true);
        $result = null;
        $err = null;
        try {
            $result = $this->driver->call($module, $action, $args);
        } catch (Exception $e) {
            $err = $e->getMessage();
        }
        $cost = round((microtime(true) - $t) * 1000);
        if ($log) {
            $raw = $this->driver instanceof HttpDriver ? $this->driver->lastResponse : '';
            try {
                Database::getInstance()->insert('uc_calls', [
                    'method'     => $module . '.' . $action,
                    'request'    => json_encode($args, JSON_UNESCAPED_UNICODE),
                    'response'   => is_scalar($result) ? (string)$result : mb_substr((string)$raw, 0, 2000),
                    'status'     => $err ? 'fail' : 'ok',
                    'code'       => '',
                    'cost_ms'    => $cost,
                    'created_at' => time(),
                ]);
            } catch (Exception $e) {
            }
            if ($err) {
                Logger::error('ucenter', 'UCenter 调用失败: ' . $module . '.' . $action, ['target' => $err]);
            }
        }
        if ($err) {
            throw new RuntimeException($err);
        }
        return $result;
    }

    // ================= 高层接口 =================

    /**
     * 检查用户名可用性
     * @return int 1可用 -1非法 -2保护词 -3已存在
     */
    public function checkName($username)
    {
        return intval($this->call('user', 'check_username', ['username' => $username]));
    }

    /**
     * 检查邮箱可用性
     * @return int 1可用 -4格式错 -5不允许 -6已存在
     */
    public function checkEmail($email)
    {
        return intval($this->call('user', 'check_email', ['email' => $email]));
    }

    /**
     * 注册用户
     * @return int >0 返回 UID；负数见 UCenter 错误码
     */
    public function register($username, $password, $email)
    {
        $uid = intval($this->call('user', 'register', [
            'username' => $username,
            'password' => $password,
            'email'    => $email,
        ]));
        Logger::write('ucenter', 'UCenter 注册: ' . $username, [
            'target' => (string)$uid,
            'extra'  => ['email' => $email, 'result' => $uid],
            'level'  => $uid > 0 ? 'info' : 'error',
        ]);
        return $uid;
    }

    /**
     * 登录校验
     * @param string $account 用户名/邮箱/UID
     * @param string $password
     * @return array|int array(uid,username,password,email,merge) / -1不存在 -2密码错 -3安全提问错
     */
    public function login($account, $password)
    {
        $isuid = ctype_digit((string)$account) ? 1 : 0;
        $res = $this->call('user', 'login', [
            'username' => $account,
            'password' => $password,
            'isuid'    => $isuid,
        ]);
        Logger::write('ucenter', 'UCenter 登录校验: ' . $account, [
            'target'  => is_array($res) ? ('uid=' . $res[0]) : ('code=' . $res),
            'level'   => is_array($res) ? 'info' : 'warn',
        ]);
        return $res;
    }

    /**
     * 修改用户资料
     * @param string $username
     * @param string $oldpw
     * @param string $newpw
     * @param string $email  空字符串表示不修改
     * @param int    $ignoreoldpw 1=不需要验证旧密码
     * @return int 1成功 0/-7未修改 -1旧密码错 -4/-6 邮箱问题 -8受保护
     */
    public function edit($username, $oldpw, $newpw, $email = '', $ignoreoldpw = 0)
    {
        $res = intval($this->call('user', 'edit', [
            'username'     => $username,
            'oldpw'        => $oldpw,
            'newpw'        => $newpw,
            'email'        => $email,
            'ignoreoldpw'  => $ignoreoldpw,
        ]));
        Logger::write('ucenter', 'UCenter 修改资料: ' . $username, [
            'target'  => (string)$res,
            'level'   => $res >= 0 ? 'info' : 'warn',
        ]);
        return $res;
    }

    /**
     * 获取用户
     * @param string $account 用户名/邮箱
     * @param int    $isuid
     * @return array|array() array(uid,username,email) / array()
     */
    public function getUser($account, $isuid = 0)
    {
        $res = $this->call('user', 'get_user', ['username' => $account, 'isuid' => $isuid]);
        return is_array($res) ? $res : [];
    }

    public function getByUid($uid)
    {
        return $this->getUser((string)$uid, 1);
    }

    public function synlogin($uid)
    {
        try {
            return $this->call('user', 'synlogin', ['uid' => $uid], false);
        } catch (Exception $e) {
            return '';
        }
    }

    public function synlogout()
    {
        try {
            return $this->call('user', 'synlogout', [], false);
        } catch (Exception $e) {
            return '';
        }
    }

    /**
     * 删除用户（账号生命周期闭环：后台用户删除 -> UCenter 侧同步注销）
     * @param int $uid
     * @return int 1 成功；0 失败；-7 表示服务端未实现该接口
     */
    public function delete($uid)
    {
        $uid = intval($uid);
        if ($uid <= 0) {
            return -1;
        }
        $r = intval($this->call('user', 'delete', ['uid' => $uid]));
        Logger::write('ucenter', 'UCenter 删除用户: uid=' . $uid, [
            'target' => (string)$r,
            'level'  => $r > 0 ? 'info' : 'warn',
        ]);
        return $r;
    }

    // ================= 诊断 =================

    public function ping()
    {
        $t = microtime(true);
        try {
            $r = $this->driver->ping();
            $r['cost_ms'] = round((microtime(true) - $t) * 1000, 2);
            return $r;
        } catch (Exception $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'detail' => []];
        }
    }

    /** 后台「一键体检」：连接/注册/登录/改密/改资料/查询 */
    public function healthCheck()
    {
        $items = [];
        $rand = substr(md5(uniqid('', true)), 0, 8);
        $user = 'tst_' . $rand;
        $email = 'tst_' . $rand . '@uic.local';
        $pwd = 'Test@12345';

        $items['connect'] = ['label' => '连接测试', 'ok' => false, 'msg' => ''];
        try {
            $p = $this->ping();
            $items['connect']['ok'] = $p['ok'];
            $items['connect']['msg'] = $p['message'];
        } catch (Exception $e) {
            $items['connect']['msg'] = $e->getMessage();
        }

        $items['check_username'] = $this->probe('用户名接口', function () use ($user) {
            $r = $this->checkName($user);
            if ($r === 1) return [true, '返回 1（可用）'];
            return [false, '返回 ' . $r];
        });

        $uid = 0;
        $items['register'] = $this->probe('注册接口', function () use ($user, $pwd, $email, &$uid) {
            $r = $this->register($user, $pwd, $email);
            if ($r > 0) { $uid = $r; return [true, '创建成功 UID=' . $r]; }
            return [false, '返回 ' . $r . ' ' . $this->errno($r)];
        });

        $items['login'] = $this->probe('登录接口', function () use ($user, $pwd) {
            $r = $this->login($user, $pwd);
            if (is_array($r) && $r[0] > 0) return [true, '校验通过 UID=' . $r[0]];
            return [false, '返回 ' . (is_array($r) ? json_encode($r) : $r) . ' ' . $this->errno($r)];
        });

        $items['getuser'] = $this->probe('用户查询', function () use ($user) {
            $r = $this->getUser($user);
            if (!empty($r)) return [true, '查到 UID=' . $r[0] . ' ' . $r[1]];
            return [false, '未查询到'];
        });

        $items['editpwd'] = $this->probe('修改密码', function () use ($user, $pwd) {
            if (!$this->probeUid($user)) return [false, '前置用户不存在'];
            $r = $this->edit($user, $pwd, $pwd . 'a', '');
            if ($r >= 0) {
                // 还原
                $this->edit($user, '', $pwd, '', 1);
                return [true, '修改成功并已还原'];
            }
            return [false, '返回 ' . $r . ' ' . $this->errno($r)];
        });

        $items['editprofile'] = $this->probe('修改资料', function () use ($user, $email) {
            $r = $this->edit($user, '', '', $email, 1);
            return [true, '返回 ' . $r . '（0/-7 表示无变更，属正常）'];
        });

        return $items;
    }

    private function probeUid($user)
    {
        return !empty($this->getUser($user));
    }

    private function probe($label, callable $fn)
    {
        try {
            list($ok, $msg) = $fn();
            return ['label' => $label, 'ok' => $ok, 'msg' => $msg];
        } catch (Exception $e) {
            return ['label' => $label, 'ok' => false, 'msg' => $e->getMessage()];
        }
    }

    public function errno($code)
    {
        if (!is_int($code) && !is_string($code)) {
            return '';
        }
        $map = [
            -1 => '用户名/密码不合法或用户不存在',
            -2 => '包含不允许注册的词语或密码错误',
            -3 => '用户名已存在',
            -4 => 'Email 格式有误',
            -5 => 'Email 不允许注册',
            -6 => 'Email 已被注册',
            -7 => '未做修改 / 接口未实现',
            -8 => '该用户受保护无权限更改',
            0  => '未做任何修改',
            1  => '成功',
        ];
        return isset($map[$code]) ? $map[$code] : '';
    }
}
