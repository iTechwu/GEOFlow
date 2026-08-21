<?php

namespace Tests\Feature;

use App\Models\Prompt;
use App\Services\GeoFlow\CatalogGeoFlowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogTenantScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_filters_content_by_team(): void
    {
        Prompt::query()->create(['name' => 'team-a-prompt', 'type' => 'content', 'content' => 'a', 'sso_team_id' => 'team-a']);
        Prompt::query()->create(['name' => 'team-b-prompt', 'type' => 'content', 'content' => 'b', 'sso_team_id' => 'team-b']);
        Prompt::query()->create(['name' => 'orphan-prompt', 'type' => 'content', 'content' => 'c', 'sso_team_id' => null]);

        $catalog = app(CatalogGeoFlowService::class);
        $teamA = $catalog->getCatalog('team-a');

        $names = collect($teamA['prompts'])->pluck('name')->all();
        $this->assertSame(['team-a-prompt'], $names);
    }

    public function test_catalog_without_team_shows_all_content(): void
    {
        Prompt::query()->create(['name' => 'team-a-prompt', 'type' => 'content', 'content' => 'a', 'sso_team_id' => 'team-a']);
        Prompt::query()->create(['name' => 'team-b-prompt', 'type' => 'content', 'content' => 'b', 'sso_team_id' => 'team-b']);

        $all = app(CatalogGeoFlowService::class)->getCatalog(null);

        $this->assertCount(2, $all['prompts']);
    }
}
