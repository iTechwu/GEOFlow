<?php

namespace Tests\Unit;

use App\Models\AiModel;
use App\Models\Task;
use App\Services\GeoFlow\HumanizeArticleService;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class HumanizeArticleServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'geoflow.ai_base_url' => 'https://models.dofe.ai/v1',
            'geoflow.ai_api_key' => 'models-service-key',
            'geoflow.humanize_enabled' => true,
            'geoflow.humanize_fail_closed' => true,
        ]);
    }

    public function test_process_audits_then_rewrites_and_normalizes_result(): void
    {
        Http::fake([
            'https://models.dofe.ai/v1/chat/completions' => Http::sequence()
                ->push($this->completion(json_encode([
                    'score' => 42,
                    'classification' => 'MIXED',
                    'issues' => [['type' => 'filler', 'text' => '值得注意的是', 'suggestion' => '直接进入事实']],
                ], JSON_UNESCAPED_UNICODE)))
                ->push($this->completion(json_encode([
                    'title' => '购车核验清单',
                    'content' => "# 购车核验清单\n\n先看合同。",
                    'audit' => [
                        'score' => 4,
                        'classification' => 'HUMAN_ONLY',
                        'issues' => [],
                    ],
                ], JSON_UNESCAPED_UNICODE))),
        ]);

        $model = $this->createChatModel();
        $result = app(HumanizeArticleService::class)->process(
            new Task(),
            $model,
            '购车核验清单',
            '值得注意的是，这是一篇需要润色的文章。',
        );

        $this->assertSame('processed', $result['status']);
        $this->assertSame('购车核验清单', $result['title']);
        $this->assertSame("# 购车核验清单\n\n先看合同。", $result['content']);
        $this->assertSame(4, $result['audit']['score']);
        $this->assertSame('HUMAN_ONLY', $result['audit']['classification']);
        $this->assertSame(2, (int) $model->fresh()->used_today);
        Http::assertSentCount(2);
    }

    public function test_fail_closed_does_not_accept_invalid_humanize_response(): void
    {
        Http::fake([
            'https://models.dofe.ai/v1/chat/completions' => Http::response($this->completion('not-json')),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('humanize 审查/润色失败');

        app(HumanizeArticleService::class)->process(
            new Task(),
            $this->createChatModel(),
            '购车核验清单',
            '这是一篇文章正文。',
        );
    }

    public function test_humanize_respects_the_model_daily_limit_before_calling_provider(): void
    {
        Http::fake();
        $model = $this->createChatModel(['daily_limit' => 1, 'used_today' => 1]);

        try {
            app(HumanizeArticleService::class)->process(
                new Task(),
                $model,
                '购车核验清单',
                '这是一篇文章正文。',
            );
            $this->fail('Expected the model daily limit to block humanize.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('humanize 审查/润色失败', $exception->getMessage());
            $this->assertStringContainsString('已达每日限制', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    /** @return array<string,mixed> */
    private function completion(string $content): array
    {
        return [
            'model' => 'test-chat-model',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => $content],
                'finish_reason' => 'stop',
            ]],
        ];
    }

    /** @param array<string,mixed> $overrides */
    private function createChatModel(array $overrides = []): AiModel
    {
        return AiModel::query()->create(array_merge([
            'name' => 'Humanize Test Chat',
            'version' => 'test',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-api-key'),
            'model_id' => 'test-chat-model',
            'model_type' => 'chat',
            'api_url' => 'https://ai.test',
            'failover_priority' => 100,
            'daily_limit' => 0,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ], $overrides));
    }
}
