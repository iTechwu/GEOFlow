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
            $roles = array_values(array_filter(array_map('strval', (array) ($claims['roles'] ?? []))));
            $attributes = [
                'email' => trim((string) ($claims['email'] ?? '')),
                'display_name' => trim((string) ($claims['name'] ?? $claims['preferred_username'] ?? '')),
                'role' => in_array('super_admin', $roles, true) ? 'super_admin' : 'sso_user',
                'status' => 'active',
                'sso_claims' => array_filter([
                    'roles' => $roles,
                    'tenant_id' => $claims['tenant_id'] ?? null,
                    'tenant_role' => $claims['tenant_role'] ?? null,
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
}
