<?php

/**
 * geo.dofe.ai REST API 路由（Laravel 默认挂载在 /api 前缀下，本文件内为 v1 子路径）。
 *
 * 中间件：api.request_id 注入/透传 X-Request-Id；throttle:api 实施令牌/IP 双层限流；api.auth 校验 Bearer；
 * api.scope:* 校验 Sanctum token abilities。幂等写操作在控制器内按 route_key 处理。
 *
 * @see bak/api/v1/index.php 遗留单入口对照
 */

use App\Http\Controllers\Api\V1\ArticleController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\JobController;
use App\Http\Controllers\Api\V1\MaterialController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\McpAuthContext;
use App\Services\Mcp\McpAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// 实际路径形如：/api/v1/...
Route::prefix('v1')
    ->middleware(['api.request_id', 'throttle:api'])
    ->group(function (): void {
        // API access uses an SSO Bearer token; local password and token issuance are disabled.
        Route::middleware(['api.auth'])->group(function (): void {
            // catalog:read — 下拉元数据（模型、提示词、库、作者、分类等）
            Route::get('catalog', [CatalogController::class, 'show'])->middleware('api.scope:catalog:read');

            // tasks:* — 任务 CRUD、启停、入队、子 Job 列表
            Route::get('tasks', [TaskController::class, 'index'])->middleware('api.scope:tasks:read');
            Route::post('tasks', [TaskController::class, 'store'])->middleware('api.scope:tasks:write');
            Route::get('tasks/{task}', [TaskController::class, 'show'])
                ->whereNumber('task')
                ->middleware('api.scope:tasks:read');
            Route::patch('tasks/{task}', [TaskController::class, 'update'])
                ->whereNumber('task')
                ->middleware('api.scope:tasks:write');
            Route::delete('tasks/{task}', [TaskController::class, 'destroy'])
                ->whereNumber('task')
                ->middleware('api.scope:tasks:write');
            Route::post('tasks/{task}/start', [TaskController::class, 'start'])
                ->whereNumber('task')
                ->middleware('api.scope:tasks:write');
            Route::post('tasks/{task}/stop', [TaskController::class, 'stop'])
                ->whereNumber('task')
                ->middleware('api.scope:tasks:write');
            Route::post('tasks/{task}/enqueue', [TaskController::class, 'enqueue'])
                ->whereNumber('task')
                ->middleware('api.scope:tasks:write');
            Route::get('tasks/{task}/jobs', [TaskController::class, 'jobs'])
                ->whereNumber('task')
                ->middleware('api.scope:tasks:read');

            // jobs:read — 单条 task_runs 执行记录
            Route::get('jobs/{job}', [JobController::class, 'show'])
                ->whereNumber('job')
                ->middleware('api.scope:jobs:read');

            // materials:* — 后台素材库 CRUD 与库内条目管理
            Route::get('materials', [MaterialController::class, 'summary'])->middleware('api.scope:materials:read');
            Route::get('materials/{type}', [MaterialController::class, 'index'])->middleware('api.scope:materials:read');
            Route::post('materials/{type}', [MaterialController::class, 'store'])->middleware('api.scope:materials:write');
            Route::get('materials/{type}/{id}', [MaterialController::class, 'show'])
                ->whereNumber('id')
                ->middleware('api.scope:materials:read');
            Route::patch('materials/{type}/{id}', [MaterialController::class, 'update'])
                ->whereNumber('id')
                ->middleware('api.scope:materials:write');
            Route::delete('materials/{type}/{id}', [MaterialController::class, 'destroy'])
                ->whereNumber('id')
                ->middleware('api.scope:materials:write');
            Route::get('materials/{type}/{id}/items', [MaterialController::class, 'items'])
                ->whereNumber('id')
                ->middleware('api.scope:materials:read');
            Route::post('materials/{type}/{id}/items', [MaterialController::class, 'storeItem'])
                ->whereNumber('id')
                ->middleware('api.scope:materials:write');
            Route::delete('materials/{type}/{id}/items', [MaterialController::class, 'destroyItems'])
                ->whereNumber('id')
                ->middleware('api.scope:materials:write');

            // articles:* — 文章 CRUD、审核、发布、软删
            Route::get('articles', [ArticleController::class, 'index'])->middleware('api.scope:articles:read');
            Route::post('articles', [ArticleController::class, 'store'])
                ->middleware(['api.scope:articles:write', 'throttle:60,1']);
            Route::get('articles/{article}', [ArticleController::class, 'show'])
                ->whereNumber('article')
                ->middleware('api.scope:articles:read');
            Route::patch('articles/{article}', [ArticleController::class, 'update'])
                ->whereNumber('article')
                ->middleware(['api.scope:articles:write', 'throttle:60,1']);
            Route::post('articles/{article}/review', [ArticleController::class, 'review'])
                ->whereNumber('article')
                ->middleware(['api.scope:articles:publish', 'throttle:60,1']);
            Route::post('articles/{article}/publish', [ArticleController::class, 'publish'])
                ->whereNumber('article')
                ->middleware(['api.scope:articles:publish', 'throttle:60,1']);
            Route::post('articles/{article}/trash', [ArticleController::class, 'trash'])
                ->whereNumber('article')
                ->middleware(['api.scope:articles:write', 'throttle:60,1']);
        });
    });

// Public API Edge adapter: the CI gateway authenticates Models API keys and
// injects a tenant-scoped McpAuthContext. The broader admin REST API remains
// SSO-only.
Route::get('yootun/v1/geoflow/overview', function (Request $request, McpAnalyticsService $analytics) {
    $auth = $request->attributes->get('mcp_auth');
    if (! $auth instanceof McpAuthContext || trim((string) $auth->tenantId) === '') {
        return response()->json([
            'error' => ['code' => 'UNAUTHORIZED', 'message' => 'A tenant-scoped Models API key is required'],
        ], 401);
    }

    $preset = (string) $request->query('preset', 'yesterday');
    if (! in_array($preset, ['today', 'yesterday', '7d', '30d', '90d', 'custom'], true)) {
        return response()->json([
            'error' => ['code' => 'VALIDATION_ERROR', 'message' => 'preset is invalid'],
        ], 422);
    }
    $input = ['preset' => $preset];
    if ($preset === 'custom') {
        $dateFrom = (string) $request->query('date_from', '');
        $dateTo = (string) $request->query('date_to', '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) !== 1) {
            return response()->json([
                'error' => ['code' => 'VALIDATION_ERROR', 'message' => 'date_from and date_to must be YYYY-MM-DD'],
            ], 422);
        }
        if (strtotime($dateTo) - strtotime($dateFrom) > 366 * 86400) {
            return response()->json([
                'error' => ['code' => 'VALIDATION_ERROR', 'message' => 'range must be at most 366 days'],
            ], 422);
        }
        $input['date_from'] = $dateFrom;
        $input['date_to'] = $dateTo;
    }

    return response()->json([
        'data' => $analytics->overview($input, $auth),
        'meta' => [
            'source' => 'geoflow',
            'requestId' => (string) $request->header('X-Request-Id', ''),
            'generatedAt' => now()->toISOString(),
        ],
    ])->header('Cache-Control', 'no-store');
})->middleware(['api.request_id', 'throttle:api', 'mcp.auth']);

// 目标达成率（docs/0903/dashboard/06 提案）：只读，目标必须归属调用方租户。
Route::get('yootun/v1/geoflow/goals', function (Request $request, McpAnalyticsService $analytics) {
    $auth = $request->attributes->get('mcp_auth');
    if (! $auth instanceof McpAuthContext || trim((string) $auth->tenantId) === '') {
        return response()->json([
            'error' => ['code' => 'UNAUTHORIZED', 'message' => 'A tenant-scoped Models API key is required'],
        ], 401);
    }

    $month = (string) $request->query('month', now()->format('Y-m'));
    if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
        return response()->json([
            'error' => ['code' => 'VALIDATION_ERROR', 'message' => 'month must be YYYY-MM'],
        ], 422);
    }

    return response()->json([
        'data' => $analytics->goals(['month' => $month], $auth),
        'meta' => [
            'source' => 'geoflow',
            'requestId' => (string) $request->header('X-Request-Id', ''),
            'generatedAt' => now()->toISOString(),
        ],
    ])->header('Cache-Control', 'no-store');
})->middleware(['api.request_id', 'throttle:api', 'mcp.auth']);
