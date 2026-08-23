<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LeadFormTenantMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_lead_forms_use_the_explicit_default_tenant_when_configured(): void
    {
        config(['geoflow.mcp_default_tenant' => 'team-a']);
        $formId = (int) DB::table('lead_forms')->insertGetId([
            'name' => 'Legacy form',
            'slug' => 'legacy-form',
            'status' => 'active',
            'fields' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_08_24_140000_backfill_lead_form_sso_team.php');
        $migration->up();

        $this->assertSame('team-a', DB::table('lead_forms')->where('id', $formId)->value('sso_team_id'));
    }
}
