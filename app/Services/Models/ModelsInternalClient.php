<?php

namespace App\Services\Models;

use App\Services\Outbound\SafeOutboundHttpClient;
use App\Support\ModelsInternalAuth;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

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
            throw new ModelsInternalCheckException('models internal HMAC 未配置：需 MODELS_INTERNAL_BASE_URL 与 MODELS_INTERNAL_API_SECRET（兼容 INTERNAL_API_SECRET）同时非空。');
        }

        if (! ModelsEndpointPolicy::allows(self::baseUrl())) {
            throw new ModelsInternalCheckException('MODELS_INTERNAL_BASE_URL 必须是有效的 HTTPS 地址。');
        }

        return Http::withHeaders(ModelsInternalAuth::headers(self::secret(), self::serviceName()))
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(30)
            ->retry([200, 500], when: static function (Throwable $exception): bool {
                if ($exception instanceof ConnectionException) {
                    return true;
                }

                return $exception instanceof RequestException
                    && ($exception->response->status() === 429 || $exception->response->serverError());
            }, throw: false);
    }

    /**
     * @param  array<string,mixed>  $query
     */
    private static function get(string $path, array $query = []): Response
    {
        $response = app(SafeOutboundHttpClient::class)->get(
            self::request(),
            self::baseUrl().$path,
            (int) config('geoflow.outbound_json_max_bytes', 4 * 1024 * 1024),
            query: $query,
        );

        if (! $response->successful()) {
            throw new ModelsInternalCheckException('models internal 探针上游返回 HTTP '.$response->status().'。');
        }

        return $response;
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

        $payload = self::responseData($response, '模型目录');
        if (! isset($payload['list'], $payload['total'])
            || ! is_array($payload['list'])
            || ! is_int($payload['total'])) {
            throw new ModelsInternalCheckException('models internal 模型目录返回格式无效。');
        }

        return $payload;
    }

    /**
     * GET /internal/models/{modelId}。
     *
     * @return array<string,mixed>
     */
    public static function getModel(string $modelId): array
    {
        $response = self::get('/internal/models/'.rawurlencode($modelId));

        return self::responseData($response, '模型详情');
    }

    /**
     * Models currently wraps successful internal responses in
     * {code,msg,data}. Keep accepting the former bare payload during rolling
     * deployments, while still rejecting malformed or failed envelopes.
     *
     * @return array<string,mixed>
     */
    private static function responseData(Response $response, string $resource): array
    {
        $payload = $response->json();
        if (! is_array($payload)) {
            throw new ModelsInternalCheckException("models internal {$resource}返回格式无效。");
        }

        if (! array_key_exists('code', $payload)) {
            return $payload;
        }

        if ($payload['code'] !== 200 || ! isset($payload['data']) || ! is_array($payload['data'])) {
            throw new ModelsInternalCheckException("models internal {$resource}返回格式无效。");
        }

        return $payload['data'];
    }
}
