<?php
/**
 * 账号注销服务
 * 流程：前端提交申请 → 后台审核 → 通过后账号停用、会话销毁、资料移入回收站（可追溯）
 */
class DeletionService
{
    /** 建表（幂等，兼容已部署库升级补齐） */
    public static function ensureTables()
    {
        $db = Database::getInstance();
        $db->exec(
            "CREATE TABLE IF NOT EXISTS account_deletion (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                uid         INT NOT NULL DEFAULT 0,
                username    VARCHAR(64)  NOT NULL DEFAULT '',
                reason      VARCHAR(500) NOT NULL DEFAULT '',
                status      VARCHAR(16)  NOT NULL DEFAULT 'pending',
                remark      VARCHAR(500) NOT NULL DEFAULT '',
                reviewer    VARCHAR(64)  NOT NULL DEFAULT '',
                created_at  INT NOT NULL DEFAULT 0,
                reviewed_at INT NOT NULL DEFAULT 0,
                KEY idx_uid (uid),
                KEY idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $db->exec(
            "CREATE TABLE IF NOT EXISTS user_recycle (
                id         INT AUTO_INCREMENT PRIMARY KEY,
                uid        INT NOT NULL DEFAULT 0,
                username   VARCHAR(64)  NOT NULL DEFAULT '',
                email      VARCHAR(128) NOT NULL DEFAULT '',
                mobile     VARCHAR(32)  NOT NULL DEFAULT '',
                profile    TEXT,
                reason     VARCHAR(500) NOT NULL DEFAULT '',
                operator   VARCHAR(64)  NOT NULL DEFAULT '',
                deleted_at INT NOT NULL DEFAULT 0,
                KEY idx_uid (uid),
                KEY idx_deleted (deleted_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public static function enabled()
    {
        return intval(Config::get('account_deletion_enabled', 1)) === 1;
    }

    /** 前端提交注销申请 */
    public static function request($uid, $reason)
    {
        self::ensureTables();
        $uid = intval($uid);
        if ($uid <= 0) {
            return [false, '参数错误'];
        }
        if (!self::enabled()) {
            return [false, '系统当前未开放账号注销'];
        }
        $db = Database::getInstance();
        $exist = $db->one("SELECT * FROM account_deletion WHERE uid=? AND status='pending' LIMIT 1", [$uid]);
        if ($exist) {
            return [false, '你已提交过注销申请，请等待管理员审核'];
        }
        $u = UserService::info($uid);
        if (!$u) {
            return [false, '账号不存在'];
        }
        $db->insert('account_deletion', [
            'uid'         => $uid,
            'username'    => (string)($u['username'] ?? ''),
            'reason'      => mb_substr(trim((string)$reason), 0, 500),
            'status'      => 'pending',
            'remark'      => '',
            'reviewer'    => '',
            'created_at'  => time(),
            'reviewed_at' => 0,
        ]);
        Logger::info('account', '提交账号注销申请', [
            'uid' => $uid,
            'extra' => ['username' => $u['username'] ?? '', 'reason' => mb_substr(trim((string)$reason), 0, 100)],
        ]);
        return [true, '注销申请已提交，等待管理员审核'];
    }

    /** 当前用户最新一条申请 */
    public static function status($uid)
    {
        self::ensureTables();
        return Database::getInstance()->one(
            "SELECT * FROM account_deletion WHERE uid=? ORDER BY id DESC LIMIT 1",
            [intval($uid)]
        );
    }

    /** 后台列表 */
    public static function listAll($status = 'pending', $page = 1, $size = 20)
    {
        self::ensureTables();
        $db   = Database::getInstance();
        $page = max(1, intval($page));
        $off  = ($page - 1) * intval($size);
        if ($status === 'all') {
            $total = intval($db->val("SELECT COUNT(*) FROM account_deletion"));
            $rows  = $db->query("SELECT * FROM account_deletion ORDER BY id DESC LIMIT " . intval($size) . " OFFSET " . intval($off));
        } else {
            $st    = $status === '' ? 'pending' : $status;
            $total = intval($db->val("SELECT COUNT(*) FROM account_deletion WHERE status=?", [$st]));
            $rows  = $db->query(
                "SELECT * FROM account_deletion WHERE status=? ORDER BY id DESC LIMIT " . intval($size) . " OFFSET " . intval($off),
                [$st]
            );
        }
        return ['total' => $total, 'rows' => $rows, 'page' => $page, 'size' => intval($size)];
    }

    public static function counts()
    {
        self::ensureTables();
        $db = Database::getInstance();
        return [
            'pending'  => intval($db->val("SELECT COUNT(*) FROM account_deletion WHERE status='pending'")),
            'approved' => intval($db->val("SELECT COUNT(*) FROM account_deletion WHERE status='approved'")),
            'rejected' => intval($db->val("SELECT COUNT(*) FROM account_deletion WHERE status='rejected'")),
            'recycle'  => intval($db->val("SELECT COUNT(*) FROM user_recycle")),
        ];
    }

    /**
     * 审核
     * @param int    $id
     * @param bool   $approve  true=通过（移入回收站） false=驳回
     * @param string $reviewer 审核人
     * @param string $remark   审核备注
     */
    public static function review($id, $approve, $reviewer, $remark = '')
    {
        self::ensureTables();
        $db = Database::getInstance();
        $row = $db->one("SELECT * FROM account_deletion WHERE id=? LIMIT 1", [intval($id)]);
        if (!$row) {
            return [false, '申请不存在'];
        }
        if ($row['status'] !== 'pending') {
            return [false, '该申请已审核，不可重复处理'];
        }
        $uid = intval($row['uid']);
        $now = time();

        if (!$approve) {
            $db->update(
                'account_deletion',
                ['status' => 'rejected', 'reviewer' => (string)$reviewer, 'remark' => mb_substr((string)$remark, 0, 500), 'reviewed_at' => $now],
                'id=?',
                [intval($id)]
            );
            Logger::info('account', '驳回账号注销申请', ['uid' => $uid, 'extra' => ['reviewer' => $reviewer]]);
            return [true, '已驳回该注销申请'];
        }

        // ---- 通过：资料快照 → 回收站 → 停用账号 → 销毁会话 ----
        $u = UserService::info($uid);
        $db->insert('user_recycle', [
            'uid'        => $uid,
            'username'   => (string)($u['username'] ?? $row['username']),
            'email'      => (string)($u['email'] ?? ''),
            'mobile'     => (string)($u['mobile'] ?? ''),
            'profile'    => json_encode($u ?: [], JSON_UNESCAPED_UNICODE),
            'reason'     => (string)$row['reason'],
            'operator'   => (string)$reviewer,
            'deleted_at' => $now,
        ]);

        // 停用账号（UCenter + 本地）
        try {
            UserService::adminToggleStatus($uid, 1);
        } catch (Exception $e) {
            Logger::error('account', '注销停用账号失败：' . $e->getMessage(), ['uid' => $uid]);
        }
        // 踢下线全部会话
        try {
            UserSession::destroyAll($uid);
        } catch (Exception $e) {
            Logger::error('account', '注销销毁会话失败：' . $e->getMessage(), ['uid' => $uid]);
        }

        $db->update(
            'account_deletion',
            ['status' => 'approved', 'reviewer' => (string)$reviewer, 'remark' => mb_substr((string)$remark, 0, 500), 'reviewed_at' => $now],
            'id=?',
            [intval($id)]
        );
        Logger::warn('account', '账号注销审核通过，已移入回收站', [
            'uid' => $uid,
            'extra' => ['username' => $row['username'], 'reviewer' => $reviewer],
        ]);
        return [true, '已通过注销申请，账号已停用并移入回收站'];
    }

    /** 回收站列表 */
    public static function recycle($page = 1, $size = 20)
    {
        self::ensureTables();
        $db   = Database::getInstance();
        $page = max(1, intval($page));
        $off  = ($page - 1) * intval($size);
        $total = intval($db->val("SELECT COUNT(*) FROM user_recycle"));
        $rows  = $db->query("SELECT * FROM user_recycle ORDER BY id DESC LIMIT " . intval($size) . " OFFSET " . intval($off));
        return ['total' => $total, 'rows' => $rows, 'page' => $page, 'size' => intval($size)];
    }
}
