<?php

namespace App\Support;

use App\Http\ApiAuthContext;
use App\Services\Sso\SsoIdentityService;

/**
 * 解析当前请求上下文的 SSO 团队（selected_team_id）。
 *
 * 用于目录内容实体创建时自动落库 sso_team_id，实现「以 SSO 为准」的租户归属：
 * - REST API：取 api_auth 上下文中的 SSO 管理员 id；
 * - 后台 Web：取 admin 会话守卫的管理员 id；
 * - CLI/队列（无鉴权上下文）：返回 null（内容为未分配，需在任务载荷中显式携带团队）。
 */
final class SsoTeamContext
{
    public static function current(): ?string
    {
        $adminId = self::currentAdminId();
        if ($adminId === null) {
            return null;
        }

        return app(SsoIdentityService::class)->selectedTeamIdForAdmin($adminId);
    }

    private static function currentAdminId(): ?int
    {
        $request = request();
        if ($request !== null) {
            $auth = $request->attributes->get('api_auth');
            if ($auth instanceof ApiAuthContext) {
                return $auth->auditAdminId;
            }
        }

        $guard = auth('admin');
        if ($guard->check()) {
            return (int) $guard->id();
        }

        return null;
    }
}
