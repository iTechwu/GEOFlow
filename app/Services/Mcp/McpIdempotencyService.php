<?php

namespace App\Services\Mcp;

use App\Models\ApiIdempotencyKey;
use App\Services\Api\IdempotencyService;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * MCP 写工具幂等适配层。
 *
 * 复用 API 的 api_idempotency_keys 存储与请求体哈希规范（{@see IdempotencyService}），
 * 但 MCP 载荷是 tools/call 参数而非 HTTP 表单，因此单独维护一个载荷无关的执行入口。
 * 幂等键由调用方在工具 arguments.idempotency_key 传入，route_key 按工具名隔离。
 */
final class McpIdempotencyService
{
    private const LEASE_SECONDS = 300;

    private const FINGERPRINT_VERSION = 2;

    /**
     * 执行一次带幂等保护的工具调用。命中缓存时直接返回上次结果，不重复执行业务。
     *
     * 幂等键命名空间按租户隔离（$tenantId 非空时在 route_key 追加租户指纹），
     * 避免不同租户使用相同 idempotency_key 时互相命中或冲突。
     *
     * @param  Closure(): array<string,mixed>  $operation
     * @return array<string,mixed>
     */
    public static function execute(string $idempotencyKey, string $toolName, array $arguments, Closure $operation, ?string $tenantId = null): array
    {
        self::assertValidKey($idempotencyKey);
        $routeKey = 'mcp:tools.call:'.$toolName;
        if ($tenantId !== null && $tenantId !== '') {
            $routeKey .= ':tenant:'.substr(hash('sha256', $tenantId), 0, 32);
        }
        $requestHash = IdempotencyService::requestHash(['tool' => $toolName, 'arguments' => $arguments]);

        $lock = Cache::lock(self::lockName($idempotencyKey, $routeKey), self::LEASE_SECONDS);
        if (! $lock->get()) {
            throw new McpToolException('相同幂等键的请求正在处理中');
        }

        try {
            $replay = self::loadReplay($idempotencyKey, $routeKey, $requestHash);
            if ($replay !== null) {
                return $replay;
            }

            $reservation = self::reserve($idempotencyKey, $routeKey, $requestHash);
            if ($reservation['replay'] !== null) {
                return $reservation['replay'];
            }

            try {
                return DB::transaction(function () use ($reservation, $requestHash, $operation): array {
                    self::claimReservation($reservation['row_id'], $requestHash, $reservation['owner_token']);
                    $data = $operation();
                    self::completeReservation($reservation['row_id'], $requestHash, $reservation['owner_token'], $data);

                    return $data;
                });
            } catch (Throwable $exception) {
                self::releaseReservation($reservation['row_id'], $reservation['owner_token']);

                throw $exception;
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function loadReplay(string $idempotencyKey, string $routeKey, string $requestHash): ?array
    {
        $row = ApiIdempotencyKey::query()
            ->where('idempotency_key', $idempotencyKey)
            ->where('route_key', $routeKey)
            ->first();

        if (! $row) {
            return null;
        }

        if ((string) $row->request_hash !== $requestHash) {
            throw new McpToolException('同一个幂等键对应了不同的工具参数');
        }

        if ($row->state === 'in_progress') {
            $expired = $row->lease_expires_at === null || now()->greaterThan($row->lease_expires_at);
            throw new McpToolException($expired ? '幂等预留已过期，请使用新的幂等键' : '相同幂等键的请求正在处理中');
        }

        $decoded = json_decode((string) $row->response_body, true);
        if (! is_array($decoded)) {
            throw new McpToolException('幂等缓存数据损坏');
        }

        return $decoded;
    }

    /**
     * @return array{row_id:int,owner_token:string,replay:?array<string,mixed>}
     */
    private static function reserve(string $idempotencyKey, string $routeKey, string $requestHash): array
    {
        $now = now();
        $ownerToken = bin2hex(random_bytes(32));
        $inserted = ApiIdempotencyKey::query()->insertOrIgnore([
            'idempotency_key' => $idempotencyKey,
            'route_key' => $routeKey,
            'request_hash' => $requestHash,
            'response_body' => '{}',
            'response_status' => 200,
            'fingerprint_version' => self::FINGERPRINT_VERSION,
            'state' => 'in_progress',
            'owner_token' => $ownerToken,
            'lease_expires_at' => $now->copy()->addSeconds(self::LEASE_SECONDS),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $row = ApiIdempotencyKey::query()
            ->where('idempotency_key', $idempotencyKey)
            ->where('route_key', $routeKey)
            ->firstOrFail();

        if ($inserted === 0) {
            return [
                'row_id' => (int) $row->getKey(),
                'owner_token' => '',
                'replay' => self::loadReplay($idempotencyKey, $routeKey, $requestHash),
            ];
        }

        return [
            'row_id' => (int) $row->getKey(),
            'owner_token' => $ownerToken,
            'replay' => null,
        ];
    }

    private static function claimReservation(int $reservationId, string $requestHash, string $ownerToken): void
    {
        $reservation = ApiIdempotencyKey::query()->whereKey($reservationId)->lockForUpdate()->first();
        if (! $reservation
            || (string) $reservation->request_hash !== $requestHash
            || (int) $reservation->fingerprint_version !== self::FINGERPRINT_VERSION
            || $reservation->state !== 'in_progress'
            || ! hash_equals((string) $reservation->owner_token, $ownerToken)) {
            throw new McpToolException('幂等预留所有权校验失败');
        }
    }

    private static function completeReservation(int $reservationId, string $requestHash, string $ownerToken, array $data): void
    {
        $updated = ApiIdempotencyKey::query()
            ->whereKey($reservationId)
            ->where('request_hash', $requestHash)
            ->where('fingerprint_version', self::FINGERPRINT_VERSION)
            ->where('state', 'in_progress')
            ->where('owner_token', $ownerToken)
            ->update([
                'response_body' => self::encodeJson($data),
                'response_status' => 200,
                'state' => 'completed',
                'owner_token' => null,
                'lease_expires_at' => null,
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            throw new McpToolException('幂等响应持久化失败');
        }
    }

    private static function releaseReservation(int $reservationId, string $ownerToken): void
    {
        try {
            ApiIdempotencyKey::query()
                ->whereKey($reservationId)
                ->where('state', 'in_progress')
                ->where('owner_token', $ownerToken)
                ->delete();
        } catch (Throwable $exception) {
            Log::error('geoflow.mcp_idempotency_release_failed', [
                'reservation_id' => $reservationId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private static function assertValidKey(string $idempotencyKey): void
    {
        if (strlen($idempotencyKey) > 120 || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $idempotencyKey) !== 1) {
            throw new McpToolException('idempotency_key 格式无效');
        }
    }

    private static function lockName(string $idempotencyKey, string $routeKey): string
    {
        return 'geoflow:mcp:idempotency:'.hash('sha256', $routeKey."\0".$idempotencyKey);
    }

    private static function encodeJson(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
