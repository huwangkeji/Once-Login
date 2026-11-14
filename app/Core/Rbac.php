<?php
/**
 * RBAC 权限体系（企业级最小权限模型）
 *
 * 角色与权限矩阵：
 *   super_admin : 全部权限（内置 uid=1 管理员，不可降级/禁用/删除）
 *   admin       : 业务管理（用户/应用/会话管理 + 全部只读）
 *   auditor     : 审计只读（仅查看类权限，无任何写操作）
 *
 * 用法：
 *   API 层   ApiRouter::requirePerm('manage:users')
 *   页面层   Rbac::gate($admin, 'manage:users')  （无权限时重定向）
 */
class Rbac
{
    /** 权限点定义（key => 中文说明，供后台展示） */
    const PERMS = [
        'manage:system'   => '设置中心（写）',
        'manage:ucenter'  => 'UCenter 配置（写）',
        'manage:admins'   => '管理员管理（写）',
        'manage:users'    => '用户管理（写）',
        'manage:apps'     => '应用管理（写）',
        'manage:sessions' => '会话管理（写）',
        'manage:logs'     => '日志清理（写）',
        'view:users'      => '用户查看',
        'view:apps'       => '应用查看',
        'view:logs'       => '日志查看',
        'view:audit'      => '审计查看',
        'view:sessions'   => '会话查看',
    ];

    /** 角色 -> 权限映射（'*' 表示全部权限） */
    const ROLE_PERMS = [
        'super_admin' => ['*'],
        'admin'       => [
            'manage:users', 'manage:apps', 'manage:sessions',
            'view:users', 'view:apps', 'view:logs', 'view:audit', 'view:sessions',
        ],
        'auditor'     => [
            'view:users', 'view:apps', 'view:logs', 'view:audit', 'view:sessions',
        ],
    ];

    /** 可用角色列表（后台管理员编辑下拉用） */
    public static function roles()
    {
        return [
            'super_admin' => '超级管理员（全部权限）',
            'admin'       => '运营管理员（业务管理）',
            'auditor'     => '审计员（只读）',
        ];
    }

    /** 判断管理员是否拥有某权限 */
    public static function can($admin, $perm)
    {
        if (!$admin || !is_array($admin)) {
            return false;
        }
        $perms = self::ROLE_PERMS[$admin['role'] ?? ''] ?? [];
        return in_array('*', $perms, true) || in_array($perm, $perms, true);
    }

    /** 判断是否超级管理员 */
    public static function isSuper($admin)
    {
        return $admin && (($admin['role'] ?? '') === 'super_admin');
    }

    /** 页面层权限门禁：无权限时 302 回控制台 */
    public static function gate($admin, $perm)
    {
        if (!self::can($admin, $perm)) {
            Response::redirect(rtrim(site_url(''), '/') . '/admin.php');
        }
    }
}