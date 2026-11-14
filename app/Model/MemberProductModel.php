<?php
/**
 * 会员中心产品展示模型
 * 后台可配置：产品名称、跳转链接、自定义展示信息、图标、排序、启用状态
 */
class MemberProductModel
{
    /**
     * 内置图标路径（stroke 风格，24x24 视窗）
     * 后台 icon 字段取值即这里的键，未知键回退到 link。
     */
    const ICONS = [
        'link'   => '<path d="M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1a5 5 0 0 0 0 7"/><path d="M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1a5 5 0 0 0 0-7"/>',
        'doc'    => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M9 13h6"/><path d="M9 17h6"/>',
        'chat'   => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
        'store'  => '<path d="M3 9l1-5h16l1 5"/><path d="M4 9v11h16V9"/><path d="M9 20v-6h6v6"/>',
        'tool'   => '<path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18l3 3 6.3-6.3a4 4 0 0 0 5.4-5.4l-2.1 2.1-2-2z"/>',
        'cloud'  => '<path d="M18 10h-1.3a4 4 0 0 0-7.4-2A3.5 3.5 0 0 0 6 15h12a3 3 0 0 0 0-6z"/>',
        'mail'   => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="M2 6l10 7 10-7"/>',
        'shield' => '<path d="M12 2l8 4v6c0 5-3.5 8-8 10-4.5-2-8-5-8-10V6z"/>',
        'star'   => '<path d="M12 2l3 7h7l-5.5 4 2 7L12 16l-6.5 4 2-7L2 9h7z"/>',
        'heart'  => '<path d="M20 8a5 5 0 0 0-8-3 5 5 0 0 0-8 3c0 5 8 10 8 10s8-5 8-10z"/>',
        'globe'  => '<circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15 15 0 0 1 0 20 15 15 0 0 1 0-20"/>',
        'code'   => '<path d="M16 18l6-6-6-6"/><path d="M8 6l-6 6 6 6"/>',
    ];

    /**
     * 渲染产品图标 SVG
     * 放在模型而非视图里：视图用 require 加载，同一进程内重复渲染布局会导致函数重复声明。
     */
    public static function iconSvg($key, $size = 16)
    {
        $paths = self::ICONS[$key] ?? self::ICONS['link'];
        $s     = intval($size) ?: 16;
        return '<svg width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none"'
            . ' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
            . $paths . '</svg>';
    }

    /** 所有可用图标键（后台下拉/校验用） */
    public static function iconKeys()
    {
        return array_keys(self::ICONS);
    }

    /**
     * 图标底色（产品磁贴用，前台侧边栏彩色圆角方块）
     */
    const ICON_TINTS = [
        'link'   => '#2563eb', 'doc'  => '#0ea5e9', 'chat' => '#22c55e',
        'store'  => '#f59e0b', 'tool' => '#64748b', 'cloud' => '#3b82f6',
        'mail'   => '#ef4444', 'shield' => '#10b981', 'star' => '#f59e0b',
        'heart'  => '#ec4899', 'globe' => '#0ea5e9', 'code' => '#8b5cf6',
    ];

    public static function iconColor($key)
    {
        return self::ICON_TINTS[$key] ?? self::ICON_TINTS['link'];
    }
    /**
     * 确保表存在（懒迁移，兼容已有安装）
     *
     * 注意：Database 的多行取值方法是 query()，不存在 all()。
     * 早期版本未把本表加入 Database::TABLES 白名单，历史库里可能存在无前缀的 member_products，
     * 这里检测到就迁移到当前前缀后再走建表，保证与全库 uic_ 前缀一致。
     */
    public static function ensureSchema()
    {
        $db = Database::getInstance();
        if (!$db->hasTable('member_products') && Database::prefix() !== '') {
            try {
                $legacy = $db->getPdo()->query("SHOW TABLES LIKE 'member_products'")->fetchColumn();
                if ($legacy) {
                    $db->getPdo()->exec(
                        'RENAME TABLE `member_products` TO ' . Database::table('member_products')
                    );
                    return;
                }
            } catch (Exception $e) {
                // 迁移失败不阻断访问，回退到正常建表流程
            }
        }
        $db->exec("CREATE TABLE IF NOT EXISTS member_products (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            name        VARCHAR(100) NOT NULL DEFAULT '',
            url         VARCHAR(500) NOT NULL DEFAULT '',
            description VARCHAR(200) NOT NULL DEFAULT '',
            icon        VARCHAR(20)  NOT NULL DEFAULT 'link',
            sort_order  INT NOT NULL DEFAULT 0,
            enabled     TINYINT NOT NULL DEFAULT 1,
            created_at  INT NOT NULL DEFAULT 0,
            updated_at  INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    /** 获取所有产品（后台管理用） */
    public static function all()
    {
        self::ensureSchema();
        return Database::getInstance()->query(
            "SELECT * FROM member_products ORDER BY sort_order ASC, id ASC"
        );
    }

    /** 获取已启用的产品列表（前台展示用） */
    public static function listEnabled()
    {
        self::ensureSchema();
        return Database::getInstance()->query(
            "SELECT * FROM member_products WHERE enabled=1 ORDER BY sort_order ASC, id ASC"
        );
    }

    /** 获取单个产品 */
    public static function get($id)
    {
        self::ensureSchema();
        return Database::getInstance()->one(
            "SELECT * FROM member_products WHERE id=?",
            [intval($id)]
        );
    }

    /** 新增产品 */
    public static function create($data)
    {
        self::ensureSchema();
        $now = time();
        // Database::insert() 已返回 lastInsertId()，Database 本身没有 lastInsertId() 方法
        return Database::getInstance()->insert('member_products', [
            'name'        => trim($data['name'] ?? ''),
            'url'         => trim($data['url'] ?? ''),
            'description' => trim($data['description'] ?? ''),
            'icon'        => trim($data['icon'] ?? 'link'),
            'sort_order'  => intval($data['sort_order'] ?? 0),
            'enabled'     => intval($data['enabled'] ?? 1),
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);
    }

    /** 更新产品 */
    public static function update($id, $data)
    {
        $upd = [];
        foreach (['name', 'url', 'description', 'icon'] as $k) {
            if (isset($data[$k])) $upd[$k] = trim($data[$k]);
        }
        if (isset($data['sort_order'])) $upd['sort_order'] = intval($data['sort_order']);
        if (isset($data['enabled'])) $upd['enabled'] = intval($data['enabled']);
        if (!$upd) return false;
        $upd['updated_at'] = time();
        return Database::getInstance()->update('member_products', $upd, 'id=?', [intval($id)]);
    }

    /** 删除产品 */
    public static function delete($id)
    {
        return Database::getInstance()->delete('member_products', 'id=?', [intval($id)]);
    }
}
