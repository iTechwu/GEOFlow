<?php

namespace App\Services\Models;

use App\Services\Outbound\SafeOutboundHttpClient;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

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
            throw new RuntimeException(
                'models 公共网关探针未配置完整，缺少：'.implode(', ', $missing).'。'
            );
        }

        self::assertSecureBaseUrl();

        $client = app(SafeOutboundHttpClient::class);
        $maxBytes = (int) config('geoflow.outbound_ai_max_bytes', 8 * 1024 * 1024);

        $chat = $client->post(self::request(), self::baseUrl().'/chat/completions', [
            'model' => self::chatModel(),
            'messages' => [['role' => 'user', 'content' => 'ping']],
            'max_tokens' => 1,
            'stream' => false,
        ], $maxBytes)->throw()->json();

        if (! is_array($chat) || ! is_array($chat['choices'] ?? null) || $chat['choices'] === []) {
            throw new RuntimeException('models Chat 探针返回格式无效。');
        }

        $embedding = $client->post(self::request(), self::baseUrl().'/embeddings', [
            'model' => self::embeddingModel(),
            'input' => 'geoflow deployment smoke',
        ], $maxBytes)->throw()->json();
        $vector = $embedding['data'][0]['embedding'] ?? null;

        if (! is_array($vector) || $vector === []) {
            throw new RuntimeException('models Embedding 探针返回格式无效。');
        }
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
            ->timeout(30);
    }

    private static function assertSecureBaseUrl(): void
    {
        $parts = parse_url(self::baseUrl());

        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || trim((string) ($parts['host'] ?? '')) === '') {
            throw new RuntimeException('MODELS_BASE_URL 必须是有效的 HTTPS 地址。');
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
