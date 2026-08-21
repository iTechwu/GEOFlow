<?php

namespace App\Support;

use App\Http\McpAuthContext;
use App\Models\McpAuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * MCP 工具审计日志记录器（读 + 写工具）。
 *
 * 与 {@see AdminActivityLogger} 分离：MCP 是无状态部署令牌，不绑定任何 SSO 管理员，
 * 因此只记录令牌指纹、作用域、工具、目标与脱敏后的参数，不记录管理员归属。
 *
 * 敏感面控制：details 只保存白名单标量（task_id / enqueue_now / job_type）与参数整体
 * SHA-256 哈希，绝不落 payload / Prompt / 正文原文，避免审计表扩大密钥与业务内容暴露面。
 */
final class McpAuditLogger
{
    public static function log(Request $request, McpAuthContext $auth, string $tool, array $arguments, string $outcome): void
    {
        try {
            $targetId = is_numeric($arguments['task_id'] ?? null) ? (int) $arguments['task_id'] : null;

            McpAuditLog::query()->create([
                'token_hash' => $auth->tokenHash,
                'scope' => $auth->scope,
                'tool' => $tool,
                'target_type' => $targetId !== null ? 'task' : '',
                'target_id' => $targetId,
                'idempotency_key' => is_string($arguments['idempotency_key'] ?? null) && ($arguments['idempotency_key'] ?? '') !== '' ? (string) $arguments['idempotency_key'] : null,
                'outcome' => $outcome,
                'request_id' => (string) ($request->attributes->get('request_id') ?? Str::uuid()->toString()),
                'ip_address' => (string) ($request->ip() ?? ''),
                'tenant' => $auth->tenant,
                'details' => self::encodeDetails(self::summarize($arguments)),
            ]);
        } catch (Throwable) {
            // 审计失败不能阻断 MCP 主流程。
        }
    }

    /**
     * 只保留白名单标量字段，并附上整体参数的 SHA-256 供事后关联排查，
     * 不保存 payload / Prompt / 正文等可能含密钥或业务内容的原文。
     *
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private static function summarize(array $arguments): array
    {
        $encoded = json_encode($arguments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $summary = [
            'arguments_sha256' => hash('sha256', is_string($encoded) ? $encoded : ''),
        ];

        foreach (['task_id', 'enqueue_now', 'job_type'] as $key) {
            if (! array_key_exists($key, $arguments) || (! is_scalar($arguments[$key]) && $arguments[$key] !== null)) {
                continue;
            }
            $summary[$key] = $arguments[$key];
        }

        return $summary;
    }

    private static function encodeDetails(array $details): string
    {
        $encoded = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : '{}';
    }
}
