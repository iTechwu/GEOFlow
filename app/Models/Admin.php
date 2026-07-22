<?php

/**
 * 后台管理员（表 `admins`）。
 *
 * Blade 后台与 API 审计共用；会话登录使用 `admin` guard。密码 `hashed` cast；`name` 访问器供界面展示。
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

class Admin extends Authenticatable
{
    protected $table = 'admins';

    protected $hidden = [
        'password',
        'remember_token',
        'sso_claims',
    ];

    protected $fillable = [
        'email',
        'display_name',
        'role',
        'status',
        'created_by',
        'last_login',
        'welcome_seen_version',
        'welcome_dismissed_at',
        'sso_sub',
        'sso_claims',
        'sso_last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'last_login' => 'datetime',
            'welcome_dismissed_at' => 'datetime',
            'created_by' => 'integer',
            'sso_claims' => 'array',
            'sso_last_synced_at' => 'datetime',
        ];
    }

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    /**
     * 顶栏等使用 `name` 展示。
     */
    public function getNameAttribute(): string
    {
        $display = trim((string) $this->display_name);

        return $display !== '' ? $display : (string) $this->sso_sub;
    }

    /**
     * 统一判断超级管理员角色，兼容历史脏值 superadmin。
     */
    public function isSuperAdmin(): bool
    {
        $claims = $this->sso_claims;
        $roles = is_array($claims) && is_array($claims['roles'] ?? null) ? $claims['roles'] : [];
        if ((string) $this->sso_sub !== '') {
            return in_array('super_admin', $roles, true) || in_array('superadmin', $roles, true);
        }
        if (in_array('super_admin', $roles, true) || in_array('superadmin', $roles, true)) {
            return true;
        }

        $role = trim(strtolower((string) ($this->role ?? '')));

        return in_array($role, ['super_admin', 'superadmin'], true);
    }

    public function canManageProtectedWorkflows(): bool
    {
        return $this->isSuperAdmin();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(self::class, 'created_by');
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(AdminActivityLog::class, 'admin_id');
    }

    public function articleReviews(): HasMany
    {
        return $this->hasMany(ArticleReview::class, 'admin_id');
    }

    public function ixicaiApiKey(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(IxicaiApiKey::class);
    }
}
