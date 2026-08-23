<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('lead_forms') || ! Schema::hasColumn('lead_forms', 'sso_team_id')) {
            return;
        }

        // Lead forms historically had no creator/owner. Only assign them when the deployment
        // explicitly declares a single default tenant; otherwise they remain unassigned.
        $teamId = trim((string) config('geoflow.mcp_default_tenant', ''));
        if ($teamId === '') {
            return;
        }

        DB::table('lead_forms')
            ->whereNull('sso_team_id')
            ->update(['sso_team_id' => $teamId, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Ownership backfills are intentionally irreversible; the source rows remain intact.
    }
};
