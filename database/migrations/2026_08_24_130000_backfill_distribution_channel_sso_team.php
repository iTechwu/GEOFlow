<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('distribution_channels')
            || ! Schema::hasColumn('distribution_channels', 'sso_team_id')
            || ! Schema::hasColumn('distribution_channels', 'created_by_admin_id')
            || ! Schema::hasTable('admins')
            || ! Schema::hasColumn('admins', 'sso_claims')) {
            return;
        }

        foreach (DB::table('admins')->whereNotNull('sso_claims')->get(['id', 'sso_claims']) as $admin) {
            $claims = is_string($admin->sso_claims) ? json_decode($admin->sso_claims, true) : $admin->sso_claims;
            $teamId = $this->teamId($claims);
            if ($teamId === null) {
                continue;
            }

            DB::table('distribution_channels')
                ->where('created_by_admin_id', (int) $admin->id)
                ->whereNull('sso_team_id')
                ->update(['sso_team_id' => $teamId, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Ownership backfills are intentionally irreversible; the source column remains intact.
    }

    /** @param mixed $claims */
    private function teamId(mixed $claims): ?string
    {
        if (! is_array($claims)) {
            return null;
        }

        foreach (['team_id', 'selected_team_id', 'current_team_id', 'tenant_id'] as $field) {
            $value = trim((string) ($claims[$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        foreach (['team', 'selected_team', 'current_team', 'tenant'] as $field) {
            $value = $claims[$field] ?? null;
            if (is_array($value) && trim((string) ($value['id'] ?? '')) !== '') {
                return trim((string) $value['id']);
            }
        }

        return null;
    }
};
