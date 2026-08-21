<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class McpAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'mcp_audit_logs';

    protected $fillable = [
        'token_hash',
        'scope',
        'tool',
        'target_type',
        'target_id',
        'idempotency_key',
        'outcome',
        'request_id',
        'ip_address',
        'details',
    ];

    protected function casts(): array
    {
        return [
            'target_id' => 'integer',
        ];
    }
}
