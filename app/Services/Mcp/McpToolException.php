<?php

namespace App\Services\Mcp;

use RuntimeException;

/**
 * MCP 工具执行期可预期失败（幂等冲突/进行中、只读令牌越权等）。
 *
 * 控制器捕获后将其渲染为 MCP tools/call 的 isError:true 结果，
 * 而不是 JSON-RPC 级错误或 5xx，便于客户端按工具语义处理。
 */
final class McpToolException extends RuntimeException
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
