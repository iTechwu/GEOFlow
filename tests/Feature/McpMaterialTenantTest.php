<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\KeywordLibrary;
use App\Services\GeoFlow\MaterialLibraryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpMaterialTenantTest extends TestCase
{
    use RefreshDatabase;

    public function test_material_service_can_create_and_query_with_an_explicit_tenant_scope(): void
    {
        $materials = app(MaterialLibraryService::class);

        $created = $materials->create('keyword-libraries', [
            'name' => 'Team A keywords',
            'description' => 'tenant scoped',
        ], 'team-a');
        KeywordLibrary::query()->create([
            'name' => 'Team B keywords',
            'description' => 'other tenant',
            'keyword_count' => 0,
            'sso_team_id' => 'team-b',
        ]);

        $this->assertDatabaseHas('keyword_libraries', [
            'id' => $created['item']['id'],
            'sso_team_id' => 'team-a',
        ]);

        $teamA = $materials->list('keyword-libraries', 1, 20, [], 'team-a');
        $this->assertSame(1, $teamA['pagination']['total']);
        $this->assertSame('Team A keywords', $teamA['items'][0]['name']);

        $summary = collect($materials->summary('team-a')['types'])->keyBy('type');
        $this->assertSame(1, $summary['keyword-libraries']['count']);
    }

    public function test_material_service_hides_records_from_other_tenants(): void
    {
        $library = KeywordLibrary::query()->create([
            'name' => 'Private keywords',
            'description' => '',
            'keyword_count' => 0,
            'sso_team_id' => 'team-b',
        ]);

        try {
            app(MaterialLibraryService::class)->show('keyword-libraries', (int) $library->id, 'team-a');
            $this->fail('Expected a tenant-scoped material_not_found error.');
        } catch (ApiException $exception) {
            $this->assertSame('material_not_found', $exception->getErrorCode());
        }
    }
}
