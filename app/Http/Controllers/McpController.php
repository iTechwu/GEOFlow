<?php

namespace App\Http\Controllers;

use App\Http\McpAuthContext;
use App\Services\GeoFlow\CatalogGeoFlowService;
use App\Services\GeoFlow\TaskLifecycleService;
use App\Services\Mcp\McpIdempotencyService;
use App\Services\Mcp\McpToolException;
use App\Support\McpAuditLogger;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * Small, stateless MCP Streamable HTTP adapter for CI automation.
 * Business operations stay in the existing GEOFlow services and API contracts.
 */
final class McpController extends Controller
{
    public function __invoke(Request $request, CatalogGeoFlowService $catalog, TaskLifecycleService $tasks): JsonResponse|Response
    {
        $payload = $request->json()->all();
        if (! is_array($payload) || (string) ($payload['jsonrpc'] ?? '') !== '2.0') {
            return $this->error(null, -32600, 'Invalid Request');
        }

        $id = $payload['id'] ?? null;
        $method = (string) ($payload['method'] ?? '');
        $params = is_array($payload['params'] ?? null) ? $payload['params'] : [];

        try {
            return match ($method) {
                'initialize' => $this->result($id, [
                    'protocolVersion' => '2025-06-18',
                    'capabilities' => ['tools' => ['listChanged' => false]],
                    'serverInfo' => [
                        'name' => (string) config('geoflow.mcp_server_name', 'geoflow'),
                        'version' => (string) config('app.version', '1.0.0'),
                    ],
                ]),
                'notifications/initialized' => $this->notification($id),
                'ping' => $this->result($id, (object) []),
                'tools/list' => $this->result($id, ['tools' => $this->tools()]),
                'tools/call' => $this->callTool($request, $id, $params, $catalog, $tasks),
                default => $this->error($id, -32601, 'Method not found'),
            };
        } catch (McpToolException $exception) {
            return $this->toolError($id, $exception->getMessage());
        } catch (\InvalidArgumentException $exception) {
            return $this->error($id, -32602, $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return $this->error($id, -32000, 'Tool execution failed');
        }
    }

    /** @return list<array<string,mixed>> */
    private function tools(): array
    {
        $empty = ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
        $taskId = ['type' => 'object', 'properties' => ['task_id' => ['type' => 'integer']], 'required' => ['task_id'], 'additionalProperties' => false];
        $idempotency = ['idempotency_key' => ['type' => 'string', 'description' => 'Optional idempotency key; retries with the same key return the cached result.']];

        return [
            ['name' => 'geoflow.catalog', 'description' => 'Read available GEOFlow models, prompts, libraries, knowledge bases and categories.', 'inputSchema' => $empty],
            ['name' => 'geoflow.tasks.list', 'description' => 'List GEOFlow tasks with status and queue progress.', 'inputSchema' => ['type' => 'object', 'properties' => ['status' => ['type' => 'string'], 'search' => ['type' => 'string'], 'page' => ['type' => 'integer', 'minimum' => 1], 'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]], 'additionalProperties' => false]],
            ['name' => 'geoflow.tasks.get', 'description' => 'Read one GEOFlow task and its monitoring summary.', 'inputSchema' => $taskId],
            ['name' => 'geoflow.tasks.start', 'description' => 'Activate a task; optionally enqueue one generation job.', 'inputSchema' => ['type' => 'object', 'properties' => array_merge(['task_id' => ['type' => 'integer'], 'enqueue_now' => ['type' => 'boolean']], $idempotency), 'required' => ['task_id'], 'additionalProperties' => false]],
            ['name' => 'geoflow.tasks.stop', 'description' => 'Pause a task and cancel pending work.', 'inputSchema' => ['type' => 'object', 'properties' => array_merge(['task_id' => ['type' => 'integer']], $idempotency), 'required' => ['task_id'], 'additionalProperties' => false]],
            ['name' => 'geoflow.tasks.enqueue', 'description' => 'Enqueue one task job.', 'inputSchema' => ['type' => 'object', 'properties' => array_merge(['task_id' => ['type' => 'integer'], 'job_type' => ['type' => 'string'], 'payload' => ['type' => 'object']], $idempotency), 'required' => ['task_id'], 'additionalProperties' => false]],
        ];
    }

    private function callTool(Request $request, mixed $id, array $params, CatalogGeoFlowService $catalog, TaskLifecycleService $tasks): JsonResponse
    {
        $auth = $this->mcpAuth($request);
        $name = (string) ($params['name'] ?? '');
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        $scoped = fn (int $taskId): int => $this->scopedTaskId($tasks, $auth, $taskId);

        $data = match ($name) {
            'geoflow.catalog' => $this->runReadTool($request, $name, $arguments, fn (array $args) => $catalog->getCatalog()),
            'geoflow.tasks.list' => $this->runReadTool($request, $name, $arguments, fn (array $args) => $tasks->listTasks((int) ($args['page'] ?? 1), (int) ($args['per_page'] ?? 20), $this->scopeFilters($auth, $args))),
            'geoflow.tasks.get' => $this->runReadTool($request, $name, $arguments, fn (array $args) => $tasks->getTask($scoped($this->taskId($args)))),
            'geoflow.tasks.start' => $this->runWriteTool($request, $name, $arguments, fn (array $args) => $tasks->startTask($scoped($this->taskId($args)), (bool) ($args['enqueue_now'] ?? false))),
            'geoflow.tasks.stop' => $this->runWriteTool($request, $name, $arguments, fn (array $args) => $tasks->stopTask($scoped($this->taskId($args)))),
            'geoflow.tasks.enqueue' => $this->runWriteTool($request, $name, $arguments, fn (array $args) => $tasks->enqueueTask($scoped($this->taskId($args)), (string) ($args['job_type'] ?? 'generate_article'), is_array($args['payload'] ?? null) ? $args['payload'] : [])),
            default => throw new \InvalidArgumentException('Unknown tool'),
        };

        return $this->result($id, ['content' => [['type' => 'text', 'text' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]], 'structuredContent' => $data]);
    }

    /**
     * 写工具统一入口：强制写作用域、剥离并接入幂等键、记录审计日志。
     *
     * @param  Closure(array<string,mixed>): array<string,mixed>  $operation
     * @return array<string,mixed>
     */
    private function runWriteTool(Request $request, string $tool, array $arguments, Closure $operation): array
    {
        $auth = $this->mcpAuth($request);
        if ($auth->scope !== McpAuthContext::SCOPE_WRITE) {
            throw new McpToolException('只读令牌无权调用写工具 '.$tool);
        }

        $idempotencyKey = is_string($arguments['idempotency_key'] ?? null) ? trim($arguments['idempotency_key']) : '';
        $stripped = $arguments;
        unset($stripped['idempotency_key']);

        try {
            $data = $idempotencyKey !== ''
                ? McpIdempotencyService::execute($idempotencyKey, $tool, $stripped, fn (): array => $operation($stripped), $auth->tenantId)
                : $operation($stripped);

            McpAuditLogger::log($request, $auth, $tool, $arguments, 'success');

            return $data;
        } catch (Throwable $exception) {
            McpAuditLogger::log($request, $auth, $tool, $arguments, 'error');

            throw $exception;
        }
    }

    /**
     * 读工具统一入口：记录审计日志（成功/失败）。
     *
     * @param  Closure(array<string,mixed>): array<string,mixed>  $operation
     * @return array<string,mixed>
     */
    private function runReadTool(Request $request, string $tool, array $arguments, Closure $operation): array
    {
        $auth = $this->mcpAuth($request);

        try {
            $data = $operation($arguments);
            McpAuditLogger::log($request, $auth, $tool, $arguments, 'success');

            return $data;
        } catch (Throwable $exception) {
            McpAuditLogger::log($request, $auth, $tool, $arguments, 'error');

            throw $exception;
        }
    }

    private function mcpAuth(Request $request): McpAuthContext
    {
        $context = $request->attributes->get('mcp_auth');
        if (! $context instanceof McpAuthContext) {
            throw new McpToolException('MCP 请求未通过鉴权');
        }

        return $context;
    }

    private function taskId(array $arguments): int
    {
        $taskId = (int) ($arguments['task_id'] ?? 0);
        if ($taskId <= 0) {
            throw new \InvalidArgumentException('task_id must be a positive integer');
        }

        return $taskId;
    }

    /**
     * 租户作用域守卫：系统令牌（tenantId 为空）不限制；
     * SSO 令牌仅能访问归属其 selected_team_id 的任务，越权统一返回任务不存在。
     */
    private function scopedTaskId(TaskLifecycleService $tasks, McpAuthContext $auth, int $taskId): int
    {
        if ($auth->tenantId !== null && $auth->tenantId !== '') {
            $tasks->ensureTaskInScope($taskId, $auth->tenantId);
        }

        return $taskId;
    }

    /**
     * 任务列表过滤：在用户过滤之上，按 SSO 租户追加 sso_team_id 过滤（系统令牌不追加）。
     *
     * @param  array<string,mixed>  $args
     * @return array<string,mixed>
     */
    private function scopeFilters(McpAuthContext $auth, array $args): array
    {
        $filters = array_filter([
            'status' => $args['status'] ?? null,
            'search' => $args['search'] ?? null,
        ], static fn ($value) => $value !== null && $value !== '');

        if ($auth->tenantId !== null && $auth->tenantId !== '') {
            $filters['sso_team_id'] = $auth->tenantId;
        }

        return $filters;
    }

    private function result(mixed $id, mixed $result): JsonResponse
    {
        return response()->json(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }

    private function toolError(mixed $id, string $message): JsonResponse
    {
        return $this->result($id, [
            'content' => [['type' => 'text', 'text' => $message]],
            'isError' => true,
        ]);
    }

    /**
     * JSON-RPC 通知不携带 id，也不应有响应体；严格客户端会将其视为协议错误。
     */
    private function notification(mixed $id): JsonResponse|Response
    {
        if ($id === null) {
            return response()->noContent();
        }

        return $this->result($id, (object) []);
    }

    private function error(mixed $id, int $code, string $message): JsonResponse
    {
        return response()->json(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]], $code === -32001 ? 401 : 200);
    }
}
