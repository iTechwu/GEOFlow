<?php

namespace Tests\Unit;

use App\Services\GeoFlow\WorkerExecutionService;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

final class WorkerKnowledgeSourceContractTest extends TestCase
{
    public function test_generation_rejects_local_knowledge_mode_before_any_local_lookup(): void
    {
        config()->set('geoflow.knowledge_read_mode', 'local');
        $reflection = new ReflectionClass(WorkerExecutionService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('resolveKnowledgeContext');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Knowledge 读取模式必须为 primary');

        $method->invoke($service, '测试标题', '测试关键词');
    }
}
