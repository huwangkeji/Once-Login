<?php
/**
 * 通行密钥（Passkey / WebAuthn）数据模型
 *
 * 存储：user_passkeys（用户绑定凭证）+ webauthn_challenges（流程挑战）
 * 与 user_oauth 同层：本地扩展凭证，不进 UCenter。
 */
class PasskeyModel
{
    /** 建表（幂等）。供已部署实例首次使用通行密钥时自动补表，无需重跑全量 schema */
    public static function ensureSchema()
    {
        $db = Database::getInstance();
        if (!$db->hasTable('user_passkeys')) {
            $db->exec("CREATE TABLE IF NOT EXISTS user_passkeys (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  uid             INT NOT NULL,
  credential_id   VARBINARY(1024) NOT NULL,
  public_key      TEXT NOT NULL,
  sign_count      INT NOT NULL DEFAULT 0,
  transports      VARCHAR(128) NOT NULL DEFAULT '',
  aaguid          VARCHAR(64)  NOT NULL DEFAULT '',
  label           VARCHAR(64)  NOT NULL DEFAULT '',
  backup_eligible INT NOT NULL DEFAULT 0,
  backup_state    INT NOT NULL DEFAULT 0,
  created_at      INT NOT NULL DEFAULT 0,
  last_used_at    INT NOT NULL DEFAULT 0,
  UNIQUE KEY uk_passkey_cred (credential_id(255)),
  KEY idx_passkey_uid (uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        if (!$db->hasTable('webauthn_challenges')) {
            $db->exec("CREATE TABLE IF NOT EXISTS webauthn_challenges (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  tx          VARCHAR(64) NOT NULL UNIQUE,
  challenge   VARBINARY(128) NOT NULL,
  type        VARCHAR(16) NOT NULL,
  uid         INT NOT NULL DEFAULT 0,
  created_at  INT NOT NULL DEFAULT 0,
  expires_at  INT NOT NULL DEFAULT 0,
  KEY idx_wa_type (type),
  KEY idx_wa_exp (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
    }

    // ==================== 凭证 ====================

    /** 按二进制 credential_id 查询（认证流程定位用户） */
    public static function findByCredentialId($binId)
    {
        $st = Database::getInstance()->prepare("SELECT * FROM user_passkeys WHERE credential_id=? LIMIT 1");
        $st->bindValue(1, $binId, PDO::PARAM_LOB);
        $st->execute();
        return $st->fetch();
    }

    /** 列出用户全部通行密钥（按绑定时间倒序） */
    public static function listByUid($uid)
    {
        return Database::getInstance()->query(
            "SELECT * FROM user_passkeys WHERE uid=? ORDER BY created_at DESC",
            [intval($uid)]
        );
    }

    /** 用户已绑定密钥数量（用于上限校验） */
    public static function countByUid($uid)
    {
        return intval(Database::getInstance()->val(
            "SELECT COUNT(*) FROM user_passkeys WHERE uid=?",
            [intval($uid)]
        ));
    }

    /** 取某用户全部 credential_id（二进制，用于注册时 excludeCredentials 防重复） */
    public static function credentialIdsByUid($uid)
    {
        $rows = Database::getInstance()->query(
            "SELECT credential_id FROM user_passkeys WHERE uid=?",
            [intval($uid)]
        );
        $out = [];
        foreach ($rows as $r) {
            // PDO MySQL 可能以资源或字符串返回 VARBINARY
            $out[] = is_resource($r['credential_id']) ? stream_get_contents($r['credential_id']) : $r['credential_id'];
        }
        return $out;
    }

    /** 新增通行密钥 */
    public static function save(array $data)
    {
        $db = Database::getInstance();
        $now = time();
        $ins = [
            'uid'             => intval($data['uid']),
            'public_key'      => $data['public_key'],
            'sign_count'      => intval($data['sign_count'] ?? 0),
            'transports'      => $data['transports'] ?? '',
            'aaguid'          => $data['aaguid'] ?? '',
            'label'           => $data['label'] ?? '',
            'backup_eligible' => intval($data['backup_eligible'] ?? 0),
            'backup_state'    => intval($data['backup_state'] ?? 0),
            'created_at'      => $now,
            'last_used_at'    => 0,
        ];
        // credential_id 为二进制，走原始 prepared 绑定（insert() 不支持 LOB）
        $st = $db->prepare(
            "INSERT INTO user_passkeys (uid,credential_id,public_key,sign_count,transports,aaguid,label,backup_eligible,backup_state,created_at,last_used_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)"
        );
        $st->bindValue(1, $ins['uid'], PDO::PARAM_INT);
        $st->bindValue(2, $data['credential_id'], PDO::PARAM_LOB);
        $st->bindValue(3, $ins['public_key'], PDO::PARAM_STR);
        $st->bindValue(4, $ins['sign_count'], PDO::PARAM_INT);
        $st->bindValue(5, $ins['transports'], PDO::PARAM_STR);
        $st->bindValue(6, $ins['aaguid'], PDO::PARAM_STR);
        $st->bindValue(7, $ins['label'], PDO::PARAM_STR);
        $st->bindValue(8, $ins['backup_eligible'], PDO::PARAM_INT);
        $st->bindValue(9, $ins['backup_state'], PDO::PARAM_INT);
        $st->bindValue(10, $ins['created_at'], PDO::PARAM_INT);
        $st->bindValue(11, $ins['last_used_at'], PDO::PARAM_INT);
        $st->execute();
        return intval($db->getPdo()->lastInsertId());
    }

    /** 更新签名计数与最后使用时间（认证成功后） */
    public static function updateSignCount($id, $signCount, $lastUsedAt = null)
    {
        return Database::getInstance()->update(
            'user_passkeys',
            ['sign_count' => intval($signCount), 'last_used_at' => intval($lastUsedAt ?? time())],
            'id=?',
            [intval($id)]
        );
    }

    /** 重命名 */
    public static function rename($id, $uid, $label)
    {
        return Database::getInstance()->update(
            'user_passkeys',
            ['label' => mb_substr(trim($label), 0, 64)],
            'id=? AND uid=?',
            [intval($id), intval($uid)]
        );
    }

    /** 删除（限定 uid 防越权） */
    public static function delete($id, $uid)
    {
        return Database::getInstance()->delete(
            'user_passkeys',
            'id=? AND uid=?',
            [intval($id), intval($uid)]
        );
    }

    /** 删除某用户全部密钥（账号注销/管理员清理时） */
    public static function deleteAllByUid($uid)
    {
        return Database::getInstance()->delete('user_passkeys', 'uid=?', [intval($uid)]);
    }

    // ==================== 挑战（challenge） ====================

    /** 签发挑战，返回事务令牌 tx（客户端持有） */
    public static function issueChallenge($type, $uid, $challengeBin, $ttl = 300)
    {
        $now = time();
        $tx  = rand_str(48);
        $st  = Database::getInstance()->prepare(
            "INSERT INTO webauthn_challenges (tx,challenge,type,uid,created_at,expires_at) VALUES (?,?,?,?,?,?)"
        );
        $st->bindValue(1, $tx, PDO::PARAM_STR);
        $st->bindValue(2, $challengeBin, PDO::PARAM_LOB);
        $st->bindValue(3, $type, PDO::PARAM_STR);
        $st->bindValue(4, intval($uid), PDO::PARAM_INT);
        $st->bindValue(5, $now, PDO::PARAM_INT);
        $st->bindValue(6, $now + intval($ttl), PDO::PARAM_INT);
        $st->execute();
        return $tx;
    }

    /** 消费挑战（取出并删除，一次性）。返回 [challenge, type, uid] 或 null */
    public static function consumeChallenge($tx)
    {
        $db = Database::getInstance();
        $st = $db->prepare("SELECT * FROM webauthn_challenges WHERE tx=? AND expires_at>? LIMIT 1");
        $st->bindValue(1, $tx, PDO::PARAM_STR);
        $st->bindValue(2, time(), PDO::PARAM_INT);
        $st->execute();
        $row = $st->fetch();
        if (!$row) {
            return null;
        }
        $db->delete('webauthn_challenges', 'id=?', [intval($row['id'])]);
        $challenge = is_resource($row['challenge']) ? stream_get_contents($row['challenge']) : $row['challenge'];
        return [
            'challenge' => $challenge,
            'type'      => $row['type'],
            'uid'       => intval($row['uid']),
        ];
    }

    /** 清理过期挑战 */
    public static function gc()
    {
        Database::getInstance()->exec("DELETE FROM webauthn_challenges WHERE expires_at<?", [time()]);
    }
}
