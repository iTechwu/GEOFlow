<?php

namespace App\Services\Mcp;

use App\Http\McpAuthContext;

/**
 * Stable capability contract for Codex/Claude Code discovery.
 *
 * This intentionally describes both exposed MCP tools and protected Admin
 * surfaces so an agent can choose a supported path without guessing routes.
 */
final class McpCapabilityService
{
    /**
     * @return array{tenant:array<string,mixed>,exposed:list<array<string,mixed>>,admin_only:list<array<string,mixed>>}
     */
    public function describe(McpAuthContext $auth): array
    {
        return [
            'tenant' => [
                'id' => $auth->tenantId,
                'mode' => $auth->tenantId !== null && $auth->tenantId !== '' ? 'tenant_scoped' : 'system_scoped',
                'cross_tenant' => $auth->tenantId === null || $auth->tenantId === '',
            ],
            'exposed' => [
                [
                    'domain' => 'catalog',
                    'status' => 'available',
                    'tools' => ['geoflow.catalog', 'geoflow.capabilities'],
                    'permission' => 'catalog:read',
                ],
                [
                    'domain' => 'content_production',
                    'status' => 'available',
                    'tools' => [
                        'geoflow.materials.*',
                        'geoflow.tasks.*',
                        'geoflow.jobs.get',
                        'geoflow.articles.*',
                    ],
                    'permission' => 'materials/tasks/articles/jobs scopes',
                    'notes' => '任务、素材、文章和执行记录按 SSO team 约束；文章审核/发布还需要 articles:publish 与审计管理员。',
                ],
                [
                    'domain' => 'url_import',
                    'status' => 'available',
                    'tools' => [
                        'geoflow.url_import.create',
                        'geoflow.url_import.run',
                        'geoflow.url_import.status',
                        'geoflow.url_import.commit',
                    ],
                    'permission' => 'materials:read/materials:write',
                    'notes' => 'create → run → status → confirmation=IMPORT 的 commit；保留 SSRF 防护并限制每租户并发任务。',
                ],
                [
                    'domain' => 'analytics',
                    'status' => 'available',
                    'tools' => ['geoflow.analytics.overview'],
                    'permission' => 'analytics:read',
                    'notes' => '仅返回按租户过滤的聚合指标；不返回原始访问日志、IP/UA、AI prompt、正文、线索或共享模型用量。',
                ],
            ],
            'admin_only' => [
                [
                    'domain' => 'distribution',
                    'status' => 'tenant_scope_required',
                    'route' => 'admin.distribution.*',
                    'reason' => '渠道和密钥是实例级资源；分发写操作还会产生远程站点副作用。',
                ],
                [
                    'domain' => 'enterprise_knowledge',
                    'status' => 'tenant_scope_required',
                    'route' => 'admin.enterprise-knowledge.*',
                    'reason' => '项目、修订和来源模型尚无租户归属，发布会创建知识库和分块。',
                ],
                [
                    'domain' => 'leads',
                    'status' => 'tenant_scope_required',
                    'route' => 'admin.lead-forms.* / admin.leads.*',
                    'reason' => '表单和线索含 PII，当前为全局模型；公共提交入口不适合作为 Agent 工具。',
                ],
                [
                    'domain' => 'themes_and_system_updates',
                    'status' => 'explicit_admin_operation',
                    'route' => 'admin.site-settings.* / admin.system-updates.*',
                    'reason' => '主题发布、系统更新和回滚属于高影响操作，需要预览、二次确认和管理员会话。',
                ],
            ],
        ];
    }
}
