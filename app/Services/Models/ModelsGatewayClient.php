<?php

namespace App\Services\Models;

use App\Services\Outbound\SafeOutboundHttpClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

final class ModelsGatewayClient
{
    public static function isConfigured(): bool
    {
        return self::baseUrl() !== ''
            && self::apiKey() !== ''
            && self::chatModel() !== ''
            && self::embeddingModel() !== '';
    }

    public static function check(): void
    {
        $missing = self::missingConfiguration();
        if ($missing !== []) {
            throw new ModelsGatewayCheckException(
                'models 公共网关探针未配置完整，缺少：'.implode(', ', $missing).'。'
            );
        }

        self::assertSecureBaseUrl();

        $client = app(SafeOutboundHttpClient::class);
        $maxBytes = (int) config('geoflow.outbound_ai_max_bytes', 8 * 1024 * 1024);

        $chatResponse = $client->post(self::request(), self::baseUrl().'/chat/completions', [
            'model' => self::chatModel(),
            'messages' => [['role' => 'user', 'content' => 'ping']],
            'max_tokens' => 1,
            'stream' => false,
        ], $maxBytes);

        if (! $chatResponse->successful()) {
            throw new ModelsGatewayCheckException('models Chat 探针上游返回 HTTP '.$chatResponse->status().'。');
        }

        $chat = $chatResponse->json();

        $chatContent = data_get($chat, 'choices.0.message.content');
        if (! is_string($chatContent) || trim($chatContent) === '') {
            throw new ModelsGatewayCheckException('models Chat 探针返回格式无效。');
        }

        $embeddingResponse = $client->post(self::request(), self::baseUrl().'/embeddings', [
            'model' => self::embeddingModel(),
            'input' => 'geoflow deployment smoke',
        ], $maxBytes);

        if (! $embeddingResponse->successful()) {
            throw new ModelsGatewayCheckException('models Embedding 探针上游返回 HTTP '.$embeddingResponse->status().'。');
        }

        $embedding = $embeddingResponse->json();
        $vector = data_get($embedding, 'data.0.embedding');

        if (! self::isNumericVector($vector)) {
            throw new ModelsGatewayCheckException('models Embedding 探针返回格式无效。');
        }
    }

    private static function isNumericVector(mixed $vector): bool
    {
        if (! is_array($vector) || $vector === []) {
            return false;
        }

        foreach ($vector as $value) {
            if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Keep the release-gate failure actionable without exposing secret values.
     * The healthcheck can therefore distinguish missing deployment input from
     * a provider route or response-format failure.
     *
     * @return list<string>
     */
    private static function missingConfiguration(): array
    {
        $values = [
            'MODELS_BASE_URL' => self::baseUrl(),
            'MODELS_API_KEY' => self::apiKey(),
            'MODELS_CHAT_SMOKE_MODEL' => self::chatModel(),
            'MODELS_EMBEDDING_SMOKE_MODEL' => self::embeddingModel(),
        ];

        return array_keys(array_filter($values, static fn (string $value): bool => $value === ''));
    }

    private static function request(): PendingRequest
    {
        return Http::withToken(self::apiKey())
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

    private static function assertSecureBaseUrl(): void
    {
        $parts = parse_url(self::baseUrl());

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = trim((string) ($parts['host'] ?? ''));
        $allowInsecureLocal = (bool) config('geoflow.models_allow_insecure_local', false);
        $isLocalHost = in_array($host, ['127.0.0.1', 'host.docker.internal', 'dofe-models-api-local'], true);
        if (! is_array($parts)
            || $host === ''
            || ($scheme !== 'https' && ! ($allowInsecureLocal && $scheme === 'http' && $isLocalHost))) {
            throw new ModelsGatewayCheckException('MODELS_BASE_URL 必须是有效的 HTTPS 地址。');
        }
    }

    private static function baseUrl(): string
    {
        return rtrim((string) config('geoflow.models_base_url', ''), '/');
    }

    private static function apiKey(): string
    {
        return trim((string) config('geoflow.models_api_key', ''));
    }

    private static function chatModel(): string
    {
        return trim((string) config('geoflow.models_chat_smoke_model', ''));
    }

    private static function embeddingModel(): string
    {
        return trim((string) config('geoflow.models_embedding_smoke_model', ''));
    }
}
