<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\McpAuthContext;
use App\Services\GeoFlow\ArticleGeoFlowService;
use App\Services\GeoFlow\CatalogGeoFlowService;
use App\Services\GeoFlow\MaterialLibraryService;
use App\Services\GeoFlow\TaskLifecycleService;
use App\Services\Mcp\McpAnalyticsService;
use App\Services\Mcp\McpCapabilityService;
use App\Services\Mcp\McpEnterpriseKnowledgeService;
use App\Services\Mcp\McpIdempotencyService;
use App\Services\Mcp\McpToolException;
use App\Services\Mcp\McpToolInputValidator;
use App\Services\Mcp\McpUrlImportService;
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
    public function __invoke(Request $request, CatalogGeoFlowService $catalog, TaskLifecycleService $tasks, ArticleGeoFlowService $articles, MaterialLibraryService $materials, McpUrlImportService $urlImports, McpCapabilityService $capabilities, McpAnalyticsService $analytics, McpEnterpriseKnowledgeService $enterpriseKnowledge, McpToolInputValidator $inputValidator): JsonResponse|Response
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
                'tools/call' => $this->callTool($request, $id, $params, $catalog, $tasks, $articles, $materials, $urlImports, $capabilities, $analytics, $enterpriseKnowledge, $inputValidator),
                default => $this->error($id, -32601, 'Method not found'),
            };
        } catch (McpToolException $exception) {
            return $this->toolError($id, $exception->getMessage());
        } catch (ApiException $exception) {
            return $this->toolError($id, $exception->getMessage(), $exception->getErrorCode(), $exception->getDetails());
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
        $empty = ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false];
        $taskId = ['type' => 'object', 'properties' => ['task_id' => ['type' => 'integer']], 'required' => ['task_id'], 'additionalProperties' => false];
        $articleId = ['type' => 'object', 'properties' => ['article_id' => ['type' => 'integer']], 'required' => ['article_id'], 'additionalProperties' => false];
        $idempotency = ['idempotency_key' => ['type' => 'string', 'description' => 'Optional idempotency key; retries with the same key return the cached result.']];
        $nullableInteger = ['anyOf' => [['type' => 'integer'], ['type' => 'null']]];
        $materialType = ['type' => 'string', 'enum' => ['categories', 'authors', 'keyword-libraries', 'title-libraries', 'image-libraries', 'knowledge-bases']];
        $materialReadableItemType = ['type' => 'string', 'enum' => ['keyword-libraries', 'title-libraries', 'image-libraries', 'knowledge-bases']];
        $materialItemType = ['type' => 'string', 'enum' => ['keyword-libraries', 'title-libraries']];

        return [
            ['name' => 'geoflow.catalog', 'description' => 'Read available GEOFlow models, prompts, libraries, knowledge bases and categories.', 'inputSchema' => $empty],
            ['name' => 'geoflow.capabilities', 'description' => 'Describe exposed MCP tools, tenant scope, permissions, and protected Admin-only GEOFlow domains.', 'inputSchema' => $empty],
            ['name' => 'geoflow.tasks.list', 'description' => 'List GEOFlow tasks with status and queue progress.', 'inputSchema' => ['type' => 'object', 'properties' => ['status' => ['type' => 'string'], 'search' => ['type' => 'string'], 'page' => ['type' => 'integer', 'minimum' => 1], 'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]], 'additionalProperties' => false]],
            ['name' => 'geoflow.tasks.create', 'description' => 'Create a GEOFlow task from catalog references and scheduling options.', 'inputSchema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'title_library_id' => ['type' => 'integer'], 'image_library_id' => $nullableInteger, 'prompt_id' => ['type' => 'integer'], 'ai_model_id' => ['type' => 'integer'], 'author_id' => $nullableInteger, 'knowledge_base_id' => $nullableInteger, 'knowledge_base_ids' => ['type' => 'array', 'items' => ['type' => 'integer']], 'status' => ['type' => 'string', 'enum' => ['active', 'paused']], 'need_review' => ['type' => 'boolean'], 'article_limit' => ['type' => 'integer'], 'draft_limit' => ['type' => 'integer'], 'sso_team_id' => ['type' => 'string'], ...$idempotency], 'required' => ['name', 'title_library_id', 'prompt_id', 'ai_model_id'], 'additionalProperties' => false]],
            ['name' => 'geoflow.tasks.get', 'description' => 'Read one GEOFlow task and its monitoring summary.', 'inputSchema' => $taskId],
            ['name' => 'geoflow.tasks.start', 'description' => 'Activate a task; optionally enqueue one generation job.', 'inputSchema' => ['type' => 'object', 'properties' => array_merge(['task_id' => ['type' => 'integer'], 'enqueue_now' => ['type' => 'boolean']], $idempotency), 'required' => ['task_id'], 'additionalProperties' => false]],
            ['name' => 'geoflow.tasks.stop', 'description' => 'Pause a task and cancel pending work.', 'inputSchema' => ['type' => 'object', 'properties' => array_merge(['task_id' => ['type' => 'integer']], $idempotency), 'required' => ['task_id'], 'additionalProperties' => false]],
            ['name' => 'geoflow.tasks.enqueue', 'description' => 'Enqueue one task job.', 'inputSchema' => ['type' => 'object', 'properties' => array_merge(['task_id' => ['type' => 'integer'], 'job_type' => ['type' => 'string'], 'payload' => ['type' => 'object']], $idempotency), 'required' => ['task_id'], 'additionalProperties' => false]],
            ['name' => 'geoflow.articles.list', 'description' => 'List GEOFlow articles and workflow status.', 'inputSchema' => ['type' => 'object', 'properties' => ['task_id' => ['type' => 'integer'], 'status' => ['type' => 'string'], 'review_status' => ['type' => 'string'], 'search' => ['type' => 'string'], 'page' => ['type' => 'integer', 'minimum' => 1], 'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]], 'additionalProperties' => false]],
            ['name' => 'geoflow.articles.get', 'description' => 'Read one article including workflow status and metadata.', 'inputSchema' => $articleId],
            ['name' => 'geoflow.articles.create', 'description' => 'Create a draft article after GEO content generation.', 'inputSchema' => ['type' => 'object', 'properties' => array_merge(['title' => ['type' => 'string'], 'content' => ['type' => 'string'], 'excerpt' => ['type' => 'string'], 'keywords' => ['type' => 'string'], 'meta_description' => ['type' => 'string'], 'category_id' => ['type' => 'integer'], 'author_id' => ['type' => 'integer'], 'task_id' => $nullableInteger, 'is_ai_generated' => ['type' => 'boolean']], $idempotency), 'required' => ['title', 'content', 'category_id', 'author_id'], 'additionalProperties' => false]],
            ['name' => 'geoflow.articles.update', 'description' => 'Update article content or metadata; content changes return it to pending review.', 'inputSchema' => ['type' => 'object', 'properties' => array_merge(['article_id' => ['type' => 'integer'], 'title' => ['type' => 'string'], 'content' => ['type' => 'string'], 'excerpt' => ['type' => 'string'], 'keywords' => ['type' => 'string'], 'meta_description' => ['type' => 'string'], 'category_id' => ['type' => 'integer'], 'author_id' => ['type' => 'integer'], 'slug' => ['type' => 'string']], $idempotency), 'required' => ['article_id'], 'additionalProperties' => false]],
            ['name' => 'geoflow.articles.review', 'description' => 'Review an article; approved content can proceed to publish.', 'inputSchema' => ['type' => 'object', 'properties' => array_merge(['article_id' => ['type' => 'integer'], 'review_status' => ['type' => 'string', 'enum' => ['pending', 'approved', 'rejected', 'auto_approved']], 'review_note' => ['type' => 'string'], 'risk_override_reason' => ['type' => 'string']], $idempotency), 'required' => ['article_id', 'review_status'], 'additionalProperties' => false]],
            ['name' => 'geoflow.articles.publish', 'description' => 'Publish an approved article.', 'inputSchema' => ['type' => 'object', 'properties' => array_merge(['article_id' => ['type' => 'integer']], $idempotency), 'required' => ['article_id'], 'additionalProperties' => false]],
            ['name' => 'geoflow.articles.trash', 'description' => 'Move an article to the trash.', 'inputSchema' => ['type' => 'object', 'properties' => array_merge(['article_id' => ['type' => 'integer']], $idempotency), 'required' => ['article_id'], 'additionalProperties' => false]],
            ['name' => 'geoflow.materials.summary', 'description' => 'Count tenant-scoped categories, authors, libraries and knowledge bases.', 'inputSchema' => $empty],
            ['name' => 'geoflow.materials.list', 'description' => 'List one tenant-scoped material type.', 'inputSchema' => ['type' => 'object', 'properties' => ['type' => $materialType, 'search' => ['type' => 'string'], 'page' => ['type' => 'integer', 'minimum' => 1], 'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]], 'required' => ['type'], 'additionalProperties' => false]],
            ['name' => 'geoflow.materials.get', 'description' => 'Read one tenant-scoped material library or record.', 'inputSchema' => ['type' => 'object', 'properties' => ['type' => $materialType, 'id' => ['type' => 'integer', 'minimum' => 1]], 'required' => ['type', 'id'], 'additionalProperties' => false]],
            ['name' => 'geoflow.materials.items.list', 'description' => 'List keyword, title, image metadata, or knowledge chunks in a tenant-scoped library.', 'inputSchema' => ['type' => 'object', 'properties' => ['type' => $materialReadableItemType, 'parent_id' => ['type' => 'integer', 'minimum' => 1], 'page' => ['type' => 'integer', 'minimum' => 1], 'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]], 'required' => ['type', 'parent_id'], 'additionalProperties' => false]],
            ['name' => 'geoflow.materials.create', 'description' => 'Create a tenant-scoped category, author, library, or knowledge base.', 'inputSchema' => ['type' => 'object', 'properties' => array_merge(['type' => $materialType, 'name' => ['type' => 'string'], 'description' => ['type' => 'string'], 'slug' => ['type' => 'string'], 'sort_order' => ['type' => 'integer', 'minimum' => 0], 'email' => ['type' => 'string'], 'bio' => ['type' => 'string'], 'avatar' => ['type' => 'string'], 'website' => ['type' => 'string'], 'social_links' => ['type' => 'string'], 'content' => ['type' => 'string'], 'file_type' => ['type' => 'string', 'enum' => ['markdown', 'word', 'text']], 'file_path' => ['type' => 'string']], $idempotency), 'required' => ['type', 'name'], 'additionalProperties' => false]],
            ['name' => 'geoflow.materials.update', 'description' => 'Update a tenant-scoped category, author, library, or knowledge base.', 'inputSchema' => ['type' => 'object', 'properties' => array_merge(['type' => $materialType, 'id' => ['type' => 'integer', 'minimum' => 1], 'name' => ['type' => 'string'], 'description' => ['type' => 'string'], 'slug' => ['type' => 'string'], 'sort_order' => ['type' => 'integer', 'minimum' => 0], 'email' => ['type' => 'string'], 'bio' => ['type' => 'string'], 'avatar' => ['type' => 'string'], 'website' => ['type' => 'string'], 'social_links' => ['type' => 'string'], 'content' => ['type' => 'string'], 'file_type' => ['type' => 'string', 'enum' => ['markdown', 'word', 'text']], 'file_path' => ['type' => 'string']], $idempotency), 'required' => ['type', 'id'], 'additionalProperties' => false]],
            ['name' => 'geoflow.materials.delete', 'description' => 'Delete a tenant-scoped material record when it is not in use.', 'inputSchema' => ['type' => 'object', 'properties' => array_merge(['type' => $materialType, 'id' => ['type' => 'integer', 'minimum' => 1]], $idempotency), 'required' => ['type', 'id'], 'additionalProperties' => false]],
            ['name' => 'geoflow.materials.items.create', 'description' => 'Add one keyword or title to a tenant-scoped library.', 'inputSchema' => ['type' => 'object', 'properties' => array_merge(['type' => $materialItemType, 'parent_id' => ['type' => 'integer', 'minimum' => 1], 'keyword' => ['type' => 'string'], 'title' => ['type' => 'string']], $idempotency), 'required' => ['type', 'parent_id'], 'additionalProperties' => false]],
            [
                'name' => 'geoflow.materials.items.delete',
                'description' => 'Delete keyword or title items from a tenant-scoped library.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => array_merge([
                        'type' => $materialItemType,
                        'parent_id' => ['type' => 'integer', 'minimum' => 1],
                        'ids' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1]],
                    ], $idempotency),
                    'required' => ['type', 'parent_id', 'ids'],
                    'additionalProperties' => false,
                ],
            ],
            ['name' => 'geoflow.tasks.update', 'description' => 'Update a tenant-scoped task configuration.', 'inputSchema' => ['type' => 'object', 'properties' => array_merge(['task_id' => ['type' => 'integer', 'minimum' => 1], 'name' => ['type' => 'string'], 'title_library_id' => ['type' => 'integer'], 'image_library_id' => $nullableInteger, 'prompt_id' => ['type' => 'integer'], 'ai_model_id' => ['type' => 'integer'], 'author_id' => $nullableInteger, 'knowledge_base_id' => $nullableInteger, 'knowledge_base_ids' => ['type' => 'array', 'items' => ['type' => 'integer']], 'status' => ['type' => 'string', 'enum' => ['active', 'paused']], 'need_review' => ['type' => 'boolean'], 'article_limit' => ['type' => 'integer'], 'draft_limit' => ['type' => 'integer']], $idempotency), 'required' => ['task_id'], 'additionalProperties' => false]],
            ['name' => 'geoflow.tasks.delete', 'description' => 'Delete a tenant-scoped task and its pending work.', 'inputSchema' => ['type' => 'object', 'properties' => array_merge(['task_id' => ['type' => 'integer', 'minimum' => 1]], $idempotency), 'required' => ['task_id'], 'additionalProperties' => false]],
            ['name' => 'geoflow.tasks.jobs', 'description' => 'List recent execution records for a tenant-scoped task.', 'inputSchema' => ['type' => 'object', 'properties' => ['task_id' => ['type' => 'integer', 'minimum' => 1], 'status' => ['type' => 'string'], 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]], 'required' => ['task_id'], 'additionalProperties' => false]],
            ['name' => 'geoflow.jobs.get', 'description' => 'Read one tenant-scoped task execution record and failure details.', 'inputSchema' => ['type' => 'object', 'properties' => ['job_id' => ['type' => 'integer', 'minimum' => 1]], 'required' => ['job_id'], 'additionalProperties' => false]],
            ['name' => 'geoflow.url_import.create', 'description' => 'Create a tenant-scoped URL import preview job. This does not write material libraries.', 'inputSchema' => ['type' => 'object', 'properties' => array_merge(['url' => ['type' => 'string'], 'project_name' => ['type' => 'string'], 'source_label' => ['type' => 'string'], 'content_language' => ['type' => 'string'], 'notes' => ['type' => 'string'], 'outputs' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['knowledge', 'keywords', 'titles']]]], $idempotency), 'required' => ['url'], 'additionalProperties' => false]],
            ['name' => 'geoflow.url_import.run', 'description' => 'Run a tenant-scoped URL import preview and return structured analysis.', 'inputSchema' => ['type' => 'object', 'properties' => array_merge(['job_id' => ['type' => 'integer', 'minimum' => 1]], $idempotency), 'required' => ['job_id'], 'additionalProperties' => false]],
            ['name' => 'geoflow.url_import.status', 'description' => 'Read a tenant-scoped URL import status and redacted preview.', 'inputSchema' => ['type' => 'object', 'properties' => ['job_id' => ['type' => 'integer', 'minimum' => 1]], 'required' => ['job_id'], 'additionalProperties' => false]],
            ['name' => 'geoflow.url_import.commit', 'description' => 'Commit a completed URL import preview into tenant-scoped knowledge, keyword, and title libraries. Requires confirmation=IMPORT.', 'inputSchema' => ['type' => 'object', 'properties' => array_merge(['job_id' => ['type' => 'integer', 'minimum' => 1], 'confirmation' => ['type' => 'string', 'enum' => ['IMPORT']]], $idempotency), 'required' => ['job_id', 'confirmation'], 'additionalProperties' => false]],
            ['name' => 'geoflow.analytics.overview', 'description' => 'Read tenant-scoped aggregate production, task, article, distribution, URL import, and traffic metrics. Raw IP, UA, prompts, content, and shared model usage are excluded.', 'inputSchema' => ['type' => 'object', 'properties' => ['preset' => ['type' => 'string', 'enum' => ['today', 'yesterday', '7d', '30d', '90d', 'custom']], 'date_from' => ['type' => 'string'], 'date_to' => ['type' => 'string'], 'task_id' => ['type' => 'integer', 'minimum' => 1], 'category_id' => ['type' => 'integer', 'minimum' => 1], 'article_id' => ['type' => 'integer', 'minimum' => 1], 'traffic_type' => ['type' => 'string', 'enum' => ['all', 'human', 'search_bot', 'ai_bot', 'other_bot', 'unknown']], 'log_source' => ['type' => 'string', 'enum' => ['all', 'local', 'server', 'channel']]], 'additionalProperties' => false]],
            ['name' => 'geoflow.enterprise_knowledge.create', 'description' => 'Create a tenant-scoped enterprise knowledge draft from text and queue AI generation.', 'inputSchema' => ['type' => 'object', 'properties' => array_merge(['name' => ['type' => 'string'], 'description' => ['type' => 'string'], 'content' => ['type' => 'string']], $idempotency), 'required' => ['name', 'content'], 'additionalProperties' => false]],
            ['name' => 'geoflow.enterprise_knowledge.status', 'description' => 'Read a tenant-scoped enterprise knowledge generation status and bounded draft preview.', 'inputSchema' => ['type' => 'object', 'properties' => ['project_id' => ['type' => 'integer', 'minimum' => 1]], 'required' => ['project_id'], 'additionalProperties' => false]],
            ['name' => 'geoflow.enterprise_knowledge.validate', 'description' => 'Validate a tenant-scoped enterprise knowledge draft against GEO safety and completeness rules.', 'inputSchema' => ['type' => 'object', 'properties' => ['project_id' => ['type' => 'integer', 'minimum' => 1], 'content' => ['type' => 'string']], 'required' => ['project_id'], 'additionalProperties' => false]],
            ['name' => 'geoflow.enterprise_knowledge.autosave', 'description' => 'Save a tenant-scoped enterprise knowledge draft and validation result.', 'inputSchema' => ['type' => 'object', 'properties' => ['project_id' => ['type' => 'integer', 'minimum' => 1], 'content' => ['type' => 'string']], 'required' => ['project_id', 'content'], 'additionalProperties' => false]],
            ['name' => 'geoflow.enterprise_knowledge.publish', 'description' => 'Publish a reviewed tenant-scoped enterprise knowledge draft into a tenant knowledge base. Requires confirmation=PUBLISH.', 'inputSchema' => ['type' => 'object', 'properties' => array_merge(['project_id' => ['type' => 'integer', 'minimum' => 1], 'confirmation' => ['type' => 'string', 'enum' => ['PUBLISH']]], $idempotency), 'required' => ['project_id', 'confirmation'], 'additionalProperties' => false]],
        ];
    }

    private function callTool(Request $request, mixed $id, array $params, CatalogGeoFlowService $catalog, TaskLifecycleService $tasks, ArticleGeoFlowService $articles, MaterialLibraryService $materials, McpUrlImportService $urlImports, McpCapabilityService $capabilities, McpAnalyticsService $analytics, McpEnterpriseKnowledgeService $enterpriseKnowledge, McpToolInputValidator $inputValidator): JsonResponse
    {
        $auth = $this->mcpAuth($request);
        $name = (string) ($params['name'] ?? '');
        $arguments = $params['arguments'] ?? [];
        if ($name === '' || ! is_array($arguments) || ($arguments !== [] && array_is_list($arguments))) {
            throw new \InvalidArgumentException('tools/call requires a tool name and object arguments');
        }
        $inputValidator->validate($this->toolSchema($name), $arguments);
        $scoped = fn (int $taskId): int => $this->scopedTaskId($tasks, $auth, $taskId);
        $scopedArticle = fn (int $articleId): int => $this->scopedArticleId($articles, $auth, $articleId);

        $data = match ($name) {
            'geoflow.catalog' => $this->runReadTool($request, $name, $arguments, fn (array $args) => $catalog->getCatalog($auth->tenantId)),
            'geoflow.capabilities' => $this->runReadTool($request, $name, $arguments, fn (array $args) => $capabilities->describe($auth)),
            'geoflow.tasks.list' => $this->runReadTool($request, $name, $arguments, fn (array $args) => $tasks->listTasks((int) ($args['page'] ?? 1), (int) ($args['per_page'] ?? 20), $this->scopeFilters($auth, $args))),
            'geoflow.tasks.create' => $this->runWriteTool($request, $name, $arguments, fn (array $args) => $tasks->createTask($this->scopedTaskCreateArguments($auth, $args))),
            'geoflow.tasks.get' => $this->runReadTool($request, $name, $arguments, fn (array $args) => $tasks->getTask($scoped($this->taskId($args)))),
            'geoflow.tasks.start' => $this->runWriteTool($request, $name, $arguments, fn (array $args) => $tasks->startTask($scoped($this->taskId($args)), (bool) ($args['enqueue_now'] ?? false))),
            'geoflow.tasks.stop' => $this->runWriteTool($request, $name, $arguments, fn (array $args) => $tasks->stopTask($scoped($this->taskId($args)))),
            'geoflow.tasks.enqueue' => $this->runWriteTool($request, $name, $arguments, fn (array $args) => $tasks->enqueueTask($scoped($this->taskId($args)), (string) ($args['job_type'] ?? 'generate_article'), is_array($args['payload'] ?? null) ? $args['payload'] : [])),
            'geoflow.articles.list' => $this->runReadTool($request, $name, $arguments, fn (array $args) => $articles->listArticles((int) ($args['page'] ?? 1), (int) ($args['per_page'] ?? 20), $this->articleFilters($auth, $args))),
            'geoflow.articles.get' => $this->runReadTool($request, $name, $arguments, fn (array $args) => $articles->getArticle($scopedArticle($this->articleId($args)))),
            'geoflow.articles.create' => $this->runWriteTool($request, $name, $arguments, function (array $args) use ($articles, $tasks, $materials, $auth): array {
                if ($auth->tenantId !== null && (! isset($args['task_id']) || $args['task_id'] === null)) {
                    throw new McpToolException('SSO 租户文章必须绑定 task_id');
                }
                if (isset($args['task_id']) && $args['task_id'] !== null) {
                    $this->scopedTaskId($tasks, $auth, (int) $args['task_id']);
                }
                $this->ensureArticleReferencesInScope($materials, $auth, $args);

                return $articles->createArticle($args, $auth->auditAdminId);
            }),
            'geoflow.articles.update' => $this->runWriteTool($request, $name, $arguments, function (array $args) use ($articles, $materials, $auth): array {
                $articleId = $this->articleId($args);
                $this->scopedArticleId($articles, $auth, $articleId);
                unset($args['article_id']);
                $this->ensureArticleReferencesInScope($materials, $auth, $args);

                return $articles->updateArticle($articleId, $args, $auth->auditAdminId);
            }),
            'geoflow.articles.review' => $this->runWriteTool($request, $name, $arguments, function (array $args) use ($articles, $auth): array {
                $articleId = $this->articleId($args);
                $this->scopedArticleId($articles, $auth, $articleId);

                return $articles->reviewArticle($articleId, (string) ($args['review_status'] ?? ''), (string) ($args['review_note'] ?? ''), (string) ($args['risk_override_reason'] ?? ''), $this->requiredAuditAdminId($auth));
            }),
            'geoflow.articles.publish' => $this->runWriteTool($request, $name, $arguments, fn (array $args) => $articles->publishArticle($scopedArticle($this->articleId($args)), $this->requiredAuditAdminId($auth))),
            'geoflow.articles.trash' => $this->runWriteTool($request, $name, $arguments, fn (array $args) => $articles->trashArticle($scopedArticle($this->articleId($args)))),
            'geoflow.materials.summary' => $this->runReadTool($request, $name, $arguments, fn (array $args) => $materials->summary($auth->tenantId)),
            'geoflow.materials.list' => $this->runReadTool($request, $name, $arguments, fn (array $args) => $materials->list((string) $args['type'], (int) ($args['page'] ?? 1), (int) ($args['per_page'] ?? 20), ['search' => (string) ($args['search'] ?? '')], $auth->tenantId)),
            'geoflow.materials.get' => $this->runReadTool($request, $name, $arguments, fn (array $args) => $materials->show((string) $args['type'], (int) $args['id'], $auth->tenantId)),
            'geoflow.materials.items.list' => $this->runReadTool($request, $name, $arguments, fn (array $args) => $materials->listItems((string) $args['type'], (int) $args['parent_id'], (int) ($args['page'] ?? 1), (int) ($args['per_page'] ?? 20), $auth->tenantId)),
            'geoflow.materials.create' => $this->runWriteTool($request, $name, $arguments, function (array $args) use ($materials, $auth): array {
                $type = (string) $args['type'];
                unset($args['type']);

                return $materials->create($type, $args, $auth->tenantId);
            }),
            'geoflow.materials.update' => $this->runWriteTool($request, $name, $arguments, function (array $args) use ($materials, $auth): array {
                $type = (string) $args['type'];
                $materialId = (int) $args['id'];
                unset($args['type'], $args['id']);

                return $materials->update($type, $materialId, $args, $auth->tenantId);
            }),
            'geoflow.materials.delete' => $this->runWriteTool($request, $name, $arguments, fn (array $args) => $materials->delete((string) $args['type'], (int) $args['id'], $auth->tenantId)),
            'geoflow.materials.items.create' => $this->runWriteTool($request, $name, $arguments, function (array $args) use ($materials, $auth): array {
                $type = (string) $args['type'];
                $parentId = (int) $args['parent_id'];
                unset($args['type'], $args['parent_id']);

                return $materials->createItem($type, $parentId, $args, $auth->tenantId);
            }),
            'geoflow.materials.items.delete' => $this->runWriteTool($request, $name, $arguments, fn (array $args) => $materials->deleteItems((string) $args['type'], (int) $args['parent_id'], ['ids' => $args['ids']], $auth->tenantId)),
            'geoflow.tasks.update' => $this->runWriteTool($request, $name, $arguments, function (array $args) use ($tasks, $auth): array {
                $taskId = $this->scopedTaskId($tasks, $auth, $this->taskId($args));
                unset($args['task_id']);

                return $tasks->updateTask($taskId, $args);
            }),
            'geoflow.tasks.delete' => $this->runWriteTool($request, $name, $arguments, fn (array $args) => $tasks->deleteTask($this->scopedTaskId($tasks, $auth, $this->taskId($args)))),
            'geoflow.tasks.jobs' => $this->runReadTool($request, $name, $arguments, fn (array $args) => $tasks->listTaskJobs($this->scopedTaskId($tasks, $auth, $this->taskId($args)), isset($args['status']) ? (string) $args['status'] : null, (int) ($args['limit'] ?? 20))),
            'geoflow.jobs.get' => $this->runReadTool($request, $name, $arguments, fn (array $args) => $tasks->getJob($this->scopedJobId($tasks, $auth, (int) $args['job_id']))),
            'geoflow.url_import.create' => $this->runWriteTool($request, $name, $arguments, fn (array $args) => $urlImports->create($args, $auth)),
            'geoflow.url_import.run' => $this->runWriteTool($request, $name, $arguments, fn (array $args) => $urlImports->run((int) $args['job_id'], $auth)),
            'geoflow.url_import.status' => $this->runReadTool($request, $name, $arguments, fn (array $args) => $urlImports->status((int) $args['job_id'], $auth)),
            'geoflow.url_import.commit' => $this->runWriteTool($request, $name, $arguments, function (array $args) use ($urlImports, $auth): array {
                if (($args['confirmation'] ?? '') !== 'IMPORT') {
                    throw new McpToolException('提交 URL 导入必须提供 confirmation=IMPORT');
                }

                return $urlImports->commit((int) $args['job_id'], $auth);
            }),
            'geoflow.analytics.overview' => $this->runReadTool($request, $name, $arguments, fn (array $args) => $analytics->overview($args, $auth)),
            'geoflow.enterprise_knowledge.create' => $this->runWriteTool($request, $name, $arguments, fn (array $args) => $enterpriseKnowledge->create($args, $auth)),
            'geoflow.enterprise_knowledge.status' => $this->runReadTool($request, $name, $arguments, fn (array $args) => $enterpriseKnowledge->status((int) $args['project_id'], $auth)),
            'geoflow.enterprise_knowledge.validate' => $this->runWriteTool($request, $name, $arguments, fn (array $args) => $enterpriseKnowledge->validate($args, $auth)),
            'geoflow.enterprise_knowledge.autosave' => $this->runWriteTool($request, $name, $arguments, fn (array $args) => $enterpriseKnowledge->autosave($args, $auth)),
            'geoflow.enterprise_knowledge.publish' => $this->runWriteTool($request, $name, $arguments, fn (array $args) => $enterpriseKnowledge->publish((int) $args['project_id'], (string) $args['confirmation'], $auth)),
            default => throw new \InvalidArgumentException('Unknown tool'),
        };

        return $this->result($id, ['content' => [['type' => 'text', 'text' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]], 'structuredContent' => $data]);
    }

    /** @return array<string,mixed> */
    private function toolSchema(string $name): array
    {
        foreach ($this->tools() as $tool) {
            if (($tool['name'] ?? null) === $name && is_array($tool['inputSchema'] ?? null)) {
                return $tool['inputSchema'];
            }
        }

        throw new \InvalidArgumentException('Unknown tool');
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
        $this->authorizeTool($auth, $tool);
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
        $this->authorizeTool($auth, $tool);

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

    private function articleId(array $arguments): int
    {
        $articleId = (int) ($arguments['article_id'] ?? 0);
        if ($articleId <= 0) {
            throw new \InvalidArgumentException('article_id must be a positive integer');
        }

        return $articleId;
    }

    private function scopedArticleId(ArticleGeoFlowService $articles, McpAuthContext $auth, int $articleId): int
    {
        $articles->ensureArticleInScope($articleId, $auth->tenantId);

        return $articleId;
    }

    /** @param array<string,mixed> $arguments */
    private function ensureArticleReferencesInScope(MaterialLibraryService $materials, McpAuthContext $auth, array $arguments): void
    {
        if ($auth->tenantId === null || $auth->tenantId === '') {
            return;
        }

        if (isset($arguments['category_id'])) {
            $materials->show('categories', (int) $arguments['category_id'], $auth->tenantId);
        }
        if (isset($arguments['author_id'])) {
            $materials->show('authors', (int) $arguments['author_id'], $auth->tenantId);
        }
    }

    /** @param array<string,mixed> $arguments */
    private function scopedTaskCreateArguments(McpAuthContext $auth, array $arguments): array
    {
        unset($arguments['idempotency_key'], $arguments['sso_owner_admin_id']);
        if ($auth->tenantId !== null && $auth->tenantId !== '') {
            $arguments['sso_team_id'] = $auth->tenantId;
        }

        return $arguments;
    }

    private function requiredAuditAdminId(McpAuthContext $auth): int
    {
        if ($auth->auditAdminId === null || $auth->auditAdminId <= 0) {
            throw new McpToolException('该 MCP 令牌未配置文章审计管理员，不能执行审核或发布');
        }

        return $auth->auditAdminId;
    }

    /** @param array<string,mixed> $arguments */
    private function articleFilters(McpAuthContext $auth, array $arguments): array
    {
        $filters = array_filter([
            'task_id' => $arguments['task_id'] ?? null,
            'status' => $arguments['status'] ?? null,
            'review_status' => $arguments['review_status'] ?? null,
            'search' => $arguments['search'] ?? null,
        ], static fn ($value) => $value !== null && $value !== '');
        if ($auth->tenantId !== null && $auth->tenantId !== '') {
            $filters['sso_team_id'] = $auth->tenantId;
        }

        return $filters;
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

    private function scopedJobId(TaskLifecycleService $tasks, McpAuthContext $auth, int $jobId): int
    {
        if ($auth->tenantId !== null && $auth->tenantId !== '') {
            $tasks->ensureJobInScope($jobId, $auth->tenantId);
        }

        return $jobId;
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

    private function authorizeTool(McpAuthContext $auth, string $tool): void
    {
        $required = match (true) {
            str_ends_with($tool, '.review'), str_ends_with($tool, '.publish') => 'articles:publish',
            str_starts_with($tool, 'geoflow.articles.') => str_ends_with($tool, '.list') || str_ends_with($tool, '.get') ? 'articles:read' : 'articles:write',
            str_starts_with($tool, 'geoflow.tasks.') => str_ends_with($tool, '.list') || str_ends_with($tool, '.get') || str_ends_with($tool, '.jobs') ? 'tasks:read' : 'tasks:write',
            str_starts_with($tool, 'geoflow.jobs.') => 'jobs:read',
            str_starts_with($tool, 'geoflow.url_import.') => $tool === 'geoflow.url_import.status' ? 'materials:read' : 'materials:write',
            str_starts_with($tool, 'geoflow.materials.') => in_array($tool, ['geoflow.materials.summary', 'geoflow.materials.list', 'geoflow.materials.get', 'geoflow.materials.items.list'], true) ? 'materials:read' : 'materials:write',
            $tool === 'geoflow.catalog' => 'catalog:read',
            $tool === 'geoflow.capabilities' => 'catalog:read',
            $tool === 'geoflow.analytics.overview' => 'analytics:read',
            str_starts_with($tool, 'geoflow.enterprise_knowledge.') => $tool === 'geoflow.enterprise_knowledge.status' ? 'materials:read' : 'materials:write',
            default => null,
        };

        if ($required !== null && ! $auth->allows($required)) {
            throw new McpToolException('当前 MCP Token 没有 '.$required.' 权限');
        }
    }

    private function toolError(mixed $id, string $message, string $code = 'tool_error', array $details = []): JsonResponse
    {
        return $this->result($id, [
            'content' => [['type' => 'text', 'text' => $message]],
            'isError' => true,
            'structuredContent' => ['error' => ['code' => $code, 'message' => $message, 'details' => $details]],
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
