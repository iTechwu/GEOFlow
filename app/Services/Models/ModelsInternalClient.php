<?php

namespace App\Services\Models;

use App\Services\Outbound\SafeOutboundHttpClient;
use App\Support\ModelsInternalAuth;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * models.dofe.ai /internal/* 管理端点客户端（服务间 HMAC 鉴权）。
 *
 * 与 models 项目 apps/api/src/modules/internal-api 对齐；鉴权头由 {@see ModelsInternalAuth}
 * 生成（Authorization: Bearer <timestamp>:<signature>:<serviceName> + x-service-name）。
 */
final class ModelsInternalClient
{
    public static function isConfigured(): bool
    {
        return self::baseUrl() !== '' && self::secret() !== '';
    }

    public static function baseUrl(): string
    {
        return rtrim((string) config('geoflow.models_internal_base_url', ''), '/');
    }

    public static function secret(): string
    {
        return trim((string) config('geoflow.models_internal_api_secret', ''));
    }

    public static function serviceName(): string
    {
        return trim((string) config('geoflow.models_service_name', 'geoflow'));
    }

    private static function request(): PendingRequest
    {
        if (! self::isConfigured()) {
            throw new RuntimeException('models internal HMAC 未配置：需 MODELS_INTERNAL_BASE_URL 与 MODELS_INTERNAL_API_SECRET（兼容 INTERNAL_API_SECRET）同时非空。');
        }

        return Http::withHeaders(ModelsInternalAuth::headers(self::secret(), self::serviceName()))
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(30);
    }

    /**
     * @param  array<string,mixed>  $query
     */
    private static function get(string $path, array $query = []): Response
    {
        return app(SafeOutboundHttpClient::class)->get(
            self::request(),
            self::baseUrl().$path,
            (int) config('geoflow.outbound_json_max_bytes', 4 * 1024 * 1024),
            query: $query,
        )->throw();
    }

    /**
     * GET /internal/models（模型目录，含租户定价视角）。
     *
     * @param  array<string,mixed>  $query
     * @return array<string,mixed>
     */
    public static function listModels(array $query = []): array
    {
        $response = self::get('/internal/models', $query);

        return $response->json() ?? [];
    }

    /**
     * GET /internal/models/{modelId}。
     *
     * @return array<string,mixed>
     */
    public static function getModel(string $modelId): array
    {
        $response = self::get('/internal/models/'.rawurlencode($modelId));

        return $response->json() ?? [];
    }
}
