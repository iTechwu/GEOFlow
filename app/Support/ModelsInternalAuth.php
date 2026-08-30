<?php

namespace App\Support;

/**
 * ixicai.cn/api /internal/v1 服务间 HMAC 鉴权客户端。
 *
 * 与 models 项目 apps/api/src/modules/internal-api/internal-auth.guard.ts 对齐：
 *   Authorization: Bearer <timestamp>:<signature>[:<serviceName>]
 *   signature = HMAC-SHA256(INTERNAL_API_SECRET, canonical)
 *   canonical = "<timestamp>:<serviceName>"（三段的 serviceName 绑定）或 "<timestamp>"（两段）
 *   timestamp 为 Unix 秒；models 侧容差 300 秒（5 分钟）。
 */
final class ModelsInternalAuth
{
    /** models 侧时间戳最大容忍年龄（秒） */
    public const MAX_TIMESTAMP_AGE_SECONDS = 300;

    public static function timestamp(): int
    {
        return time();
    }

    /**
     * 计算 HMAC-SHA256 签名（hex，与 models 侧 createHmac('sha256').digest('hex') 一致）。
     */
    public static function signature(string $secret, string $timestamp, ?string $serviceName = null): string
    {
        $canonical = $serviceName !== null && $serviceName !== ''
            ? $timestamp.':'.$serviceName
            : $timestamp;

        return hash_hmac('sha256', $canonical, $secret);
    }

    /**
     * 生成 Bearer 令牌：<timestamp>:<signature>[:<serviceName>]。
     */
    public static function token(string $secret, ?string $serviceName = null, ?int $timestamp = null): string
    {
        $ts = (string) ($timestamp ?? self::timestamp());
        $sig = self::signature($secret, $ts, $serviceName);

        return $serviceName !== null && $serviceName !== ''
            ? $ts.':'.$sig.':'.$serviceName
            : $ts.':'.$sig;
    }

    /**
     * 生成完整鉴权请求头。
     *
     * @return array{Authorization:string, 'x-service-name'?:string}
     */
    public static function headers(string $secret, ?string $serviceName = null): array
    {
        $headers = ['Authorization' => 'Bearer '.self::token($secret, $serviceName)];
        if ($serviceName !== null && $serviceName !== '') {
            $headers['x-service-name'] = $serviceName;
        }

        return $headers;
    }
}
