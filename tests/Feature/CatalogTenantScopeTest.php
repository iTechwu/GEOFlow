<?php

namespace Tests\Feature;

use App\Models\Prompt;
use App\Services\GeoFlow\CatalogGeoFlowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogTenantScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_mcp_prompt_creation_is_persisted_and_visible_to_the_same_tenant(): void
    {
        $service = app(CatalogGeoFlowService::class);

        $created = $service->createPrompt([
            'name' => '优惠豚每日 GEO 提示词',
            'content' => '基于可核验事实生成内容。',
        ], 'team-a');

        $this->assertDatabaseHas('prompts', [
            'id' => $created['id'],
            'name' => '优惠豚每日 GEO 提示词',
            'type' => 'content',
            'content' => '基于可核验事实生成内容。',
            'sso_team_id' => 'team-a',
        ]);
        $this->assertSame(
            ['优惠豚每日 GEO 提示词'],
            collect($service->getCatalog('team-a')['prompts'])->pluck('name')->all(),
        );
        $this->assertSame([], $service->getCatalog('team-b')['prompts']);
    }

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
        $teamA = Prompt::query()->create(['name' => 'team-a-prompt', 'type' => 'content', 'content' => 'a', 'sso_team_id' => 'team-a']);
        $teamB = Prompt::query()->create(['name' => 'team-b-prompt', 'type' => 'content', 'content' => 'b', 'sso_team_id' => 'team-b']);

        $all = app(CatalogGeoFlowService::class)->getCatalog(null);
        $ids = collect($all['prompts'])->pluck('id');

        $this->assertTrue($ids->contains($teamA->id));
        $this->assertTrue($ids->contains($teamB->id));
    }
}
