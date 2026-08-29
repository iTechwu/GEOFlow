<?php

namespace Tests\Unit;

use App\Services\GeoFlow\WorkerExecutionService;
use ReflectionClass;
use Tests\TestCase;

final class RemoteKnowledgeContextTest extends TestCase
{
    public function test_remote_context_has_citable_ids_and_a_hard_character_budget(): void
    {
        $reflection = new ReflectionClass(WorkerExecutionService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('formatRemoteKnowledgeContext');
        $method->setAccessible(true);

        $context = $method->invoke($service, [
            ['title' => 'Policy', 'content' => str_repeat('甲', 100)],
            ['title' => 'Guide', 'content' => str_repeat('乙', 100)],
        ], 80);

        $this->assertStringStartsWith('[K1] Policy', $context);
        $this->assertLessThanOrEqual(80, mb_strlen($context, 'UTF-8'));
        $this->assertStringNotContainsString('[knowledge]', $context);
    }
}
