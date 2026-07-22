<?php

namespace App\Services\Sso;

use App\Models\Admin;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class SsoIdentityService
{
    /** @param array<string,mixed> $claims */
    public function synchronize(array $claims): Admin
    {
        $sub = trim((string) ($claims['sub'] ?? ''));
        if ($sub === '') {
            throw new RuntimeException('SSO identity has no subject.');
        }

        return DB::transaction(function () use ($claims, $sub): Admin {
            $admin = Admin::query()->where('sso_sub', $sub)->lockForUpdate()->first();
            $roles = $this->roles($claims);
            $attributes = [
                'email' => trim((string) ($claims['email'] ?? '')),
                'display_name' => trim((string) ($claims['name'] ?? $claims['preferred_username'] ?? '')),
                'role' => in_array('super_admin', $roles, true) ? 'super_admin' : 'sso_user',
                'status' => 'active',
                'sso_claims' => array_filter([
                    'roles' => $roles,
                    // ixicai expects this value as x-current-tenant (the selected SSO Team.id).
                    'selected_team_id' => $this->selectedTeamId($claims),
                    'tenant_role' => $claims['tenant_role'] ?? null,
                    'scopes' => $this->scopes($claims),
                ], static fn (mixed $value): bool => $value !== null && $value !== []),
                'sso_last_synced_at' => now(),
                'last_login' => now(),
            ];
            if ($admin instanceof Admin) {
                $admin->forceFill($attributes)->save();
                return $admin;
            }

            return Admin::query()->create($attributes + ['sso_sub' => $sub]);
        });
    }

    /** @param array<string,mixed> $claims
     *  @return list<string>
     */
    private function roles(array $claims): array
    {
        $raw = $claims['roles'] ?? $claims['role'] ?? [];
        $roles = is_string($raw) ? preg_split('/\s+/', trim($raw)) : (array) $raw;
        if (($claims['isAdmin'] ?? false) === true) {
            $roles[] = 'super_admin';
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $role): string => trim(strtolower((string) $role)),
            $roles,
        ))));
    }

    /** @param array<string,mixed> $claims */
    private function selectedTeamId(array $claims): ?string
    {
        foreach (['team_id', 'selected_team_id', 'current_team_id', 'tenant_id'] as $field) {
            $value = trim((string) ($claims[$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        foreach (['team', 'selected_team', 'current_team', 'tenant'] as $field) {
            $value = $claims[$field] ?? null;
            if (is_array($value) && is_string($value['id'] ?? null) && trim($value['id']) !== '') {
                return trim($value['id']);
            }
        }

        return null;
    }

    /** @param array<string,mixed> $claims
     *  @return list<string>
     */
    private function scopes(array $claims): array
    {
        $raw = $claims['scopes'] ?? $claims['scope'] ?? $claims['permissions'] ?? [];
        $scopes = is_string($raw) ? preg_split('/\s+/', trim($raw)) : (array) $raw;

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $scope): string => trim((string) $scope),
            $scopes,
        ))));
    }
}
