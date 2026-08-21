<?php

namespace App\Models\Concerns;

use App\Support\SsoTeamContext;

/**
 * 在模型创建时自动填充 sso_team_id（以 SSO 为准）。
 *
 * 仅当模型可填充 sso_team_id 且未显式提供时，从当前请求上下文解析团队并落库；
 * 后台/REST 的交互式创建会被正确归属，CLI/队列（无上下文）保持 null（未分配）。
 */
trait StampsSsoTeam
{
    public static function bootStampsSsoTeam(): void
    {
        static::creating(static function ($model): void {
            if (! $model->isFillable('sso_team_id')) {
                return;
            }
            if ($model->getAttribute('sso_team_id') !== null) {
                return;
            }

            $model->setAttribute('sso_team_id', SsoTeamContext::current());
        });
    }
}
