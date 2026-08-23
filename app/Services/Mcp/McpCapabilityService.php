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
                [
                    'domain' => 'enterprise_knowledge',
                    'status' => 'available',
                    'tools' => [
                        'geoflow.enterprise_knowledge.create',
                        'geoflow.enterprise_knowledge.status',
                        'geoflow.enterprise_knowledge.validate',
                        'geoflow.enterprise_knowledge.autosave',
                        'geoflow.enterprise_knowledge.publish',
                    ],
                    'permission' => 'materials:read/materials:write',
                    'notes' => '文本创建 → 异步生成 → status/validate/autosave → confirmation=PUBLISH 发布；文件上传、图片编辑和删除仍走 Admin。',
                ],
                [
                    'domain' => 'site_read',
                    'status' => 'available',
                    'tools' => [
                        'geoflow.site.search',
                        'geoflow.site.article',
                        'geoflow.site.archive',
                    ],
                    'permission' => 'articles:read',
                    'notes' => '仅返回已发布且属于当前 SSO team 的站点内容；详情读取不增加 view_count，正文有长度上限。',
                ],
                [
                    'domain' => 'frontend_discovery',
                    'status' => 'available',
                    'tools' => ['geoflow.site.capabilities'],
                    'permission' => 'catalog:read',
                    'notes' => '只读返回主题 manifest 和主页模块契约；主题切换、复制、发布仍需 Admin。',
                ],
                [
                    'domain' => 'system_diagnostics',
                    'status' => 'available',
                    'tools' => ['geoflow.system.status'],
                    'permission' => 'catalog:read',
                    'notes' => '只返回版本、队列、数据库可达性、迁移数量和扩展能力；不会执行更新、Shell、远程探针或返回主机/密钥。',
                ],
                [
                    'domain' => 'distribution_read',
                    'status' => 'available',
                    'tools' => [
                        'geoflow.distribution.channels',
                        'geoflow.distribution.jobs',
                        'geoflow.distribution.health',
                    ],
                    'permission' => 'distribution:read',
                    'notes' => '只读取已补充 sso_team_id 的渠道及其文章分发作业；不会触发远端健康检查，不返回密钥、配置或错误原文。',
                ],
                [
                    'domain' => 'leads_read',
                    'status' => 'available',
                    'tools' => [
                        'geoflow.leads.forms',
                        'geoflow.leads.submissions',
                        'geoflow.leads.get',
                        'geoflow.leads.update_status',
                    ],
                    'permission' => 'leads:read/leads:write; leads:pii for payload',
                    'notes' => '表单必须有 sso_team_id；默认只返回状态、时间和 payload 字段名，原始 payload 需要独立 leads:pii scope。',
                ],
            ],
            'admin_only' => [
                [
                    'domain' => 'distribution',
                    'status' => 'explicit_admin_operation',
                    'route' => 'admin.distribution.*',
                    'reason' => '渠道创建、重试、暂停、激活、远端设置同步、密钥轮换/显示、站点包下载和删除仍会产生远程副作用或涉及敏感凭据。',
                ],
                [
                    'domain' => 'leads',
                    'status' => 'explicit_admin_operation',
                    'route' => 'admin.lead-forms.* / admin.leads.*',
                    'reason' => '表单删除、结构修改、原始 CSV 导出和公共提交仍需 Admin；MCP 不替代匿名公共提交，也不开放原始导出。',
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
