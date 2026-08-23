<?php

namespace App\Services\Sso;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

final class SsoOidcClient
{
    public function __construct(private readonly HttpFactory $http) {}

    public function begin(Request $request): string
    {
        $state = Str::random(64);
        $nonce = Str::random(64);
        $verifier = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
        $issuedAt = now()->timestamp;
        $request->session()->put('sso.oidc', compact('state', 'nonce', 'verifier', 'issuedAt'));

        $metadata = $this->metadata();
        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => config('sso.client_id'),
            'redirect_uri' => config('sso.redirect_uri'),
            'scope' => config('sso.scope'),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);

        return rtrim((string) $metadata['authorization_endpoint'], '?').'?'.$query;
    }

    /** @return array{claims: array<string,mixed>, access_token: string, id_token: string, expires_in: int} */
    public function complete(Request $request): array
    {
        $stored = $request->session()->pull('sso.oidc');
        $state = (string) $request->query('state', '');
        $code = (string) $request->query('code', '');
        $issuedAt = (int) ($stored['issuedAt'] ?? 0);
        $stateTtl = max(60, (int) config('sso.state_ttl_seconds'));
        if (! is_array($stored)
            || $issuedAt === 0
            || $issuedAt < now()->subSeconds($stateTtl)->timestamp
            || ! hash_equals((string) ($stored['state'] ?? ''), $state)
            || $code === '') {
            throw new RuntimeException('SSO login verification failed. Please start sign-in again.');
        }

        $metadata = $this->metadata();
        try {
            $response = $this->http->asForm()
                ->withBasicAuth((string) config('sso.client_id'), (string) config('sso.client_secret'))
                ->connectTimeout(5)->timeout(15)->retry(2, 200, throw: false)
                ->post($this->internalizeUrl((string) $metadata['token_endpoint']), [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => config('sso.redirect_uri'),
                    'client_id' => config('sso.client_id'),
                    'code_verifier' => $stored['verifier'],
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('SSO is currently unavailable.', 0, $exception);
        }

        if (! $response->successful() || ! is_array($response->json())) {
            throw new RuntimeException('SSO rejected the sign-in callback.');
        }
        $payload = $response->json();
        $idToken = (string) ($payload['id_token'] ?? '');
        $accessToken = (string) ($payload['access_token'] ?? '');
        if ($idToken === '' || $accessToken === '') {
            throw new RuntimeException('SSO did not return the required identity token.');
        }
        if (isset($payload['token_type']) && ! hash_equals('bearer', strtolower((string) $payload['token_type']))) {
            throw new RuntimeException('SSO returned an unsupported token type.');
        }

        $claims = $this->verifyJwt($idToken, (string) ($stored['nonce'] ?? ''));
        $profile = $this->userInfoClaims(
            $accessToken,
            $this->internalizeUrl((string) $metadata['userinfo_endpoint']),
        );
        if (($profile['sub'] ?? null) !== ($claims['sub'] ?? null)) {
            throw new RuntimeException('SSO identity response did not match the signed token.');
        }

        return [
            'claims' => array_replace($claims, $profile),
            'access_token' => $accessToken,
            'id_token' => $idToken,
            'expires_in' => max(60, (int) ($payload['expires_in'] ?? config('sso.session_lifetime'))),
        ];
    }

    /**
     * Resolve an access token against the SSO userinfo endpoint. Successful
     * responses are cached briefly by token hash; failed validations are never cached.
     *
     * @return array<string,mixed>
     */
    public function userInfoClaims(string $accessToken, ?string $endpoint = null): array
    {
        $cacheKey = 'sso:token:userinfo:'.hash('sha256', $accessToken);
        $ttl = max(1, (int) config('sso.token_cache_seconds'));

        // Use a relative TTL so a slow upstream call does not consume the
        // entire cache lifetime before Cache::remember stores the response.
        return Cache::remember($cacheKey, $ttl, fn (): array => $this->userInfo(
            $accessToken,
            $endpoint ?? $this->internalApiUrl('/oauth/userinfo'),
        ));
    }

    public function logoutUrl(?string $idTokenHint = null): string
    {
        $url = rtrim((string) config('sso.api_url'), '/').'/oauth/logout';
        $query = [
            'post_logout_redirect_uri' => route('admin.login'),
            'client_id' => config('sso.client_id'),
        ];
        if (is_string($idTokenHint) && $idTokenHint !== '') {
            $query['id_token_hint'] = $idTokenHint;
        }

        return $url.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /** @return array<string,mixed> */
    private function metadata(): array
    {
        return Cache::remember('sso:oidc:metadata', now()->addHour(), function (): array {
            $response = $this->http->acceptJson()->connectTimeout(5)->timeout(10)->retry(2, 200, throw: false)
                ->get($this->internalizeUrl((string) config('sso.discovery_url')));
            $metadata = $response->json();
            foreach (['issuer', 'authorization_endpoint', 'token_endpoint', 'userinfo_endpoint', 'jwks_uri'] as $field) {
                if (! $response->successful() || ! is_array($metadata) || ! is_string($metadata[$field] ?? null) || $metadata[$field] === '') {
                    throw new RuntimeException('SSO discovery metadata is incomplete.');
                }
            }
            if (! hash_equals((string) config('sso.issuer'), rtrim((string) $metadata['issuer'], '/'))) {
                throw new RuntimeException('SSO discovery issuer does not match configured issuer.');
            }
            return $metadata;
        });
    }

    /** @return array<string,mixed> */
    private function userInfo(string $accessToken, string $endpoint): array
    {
        $response = $this->http->acceptJson()->withToken($accessToken)->connectTimeout(5)->timeout(10)->retry(2, 200, throw: false)
            ->get($endpoint);
        $profile = $response->json();
        if (! $response->successful() || ! is_array($profile) || ! is_string($profile['sub'] ?? null)) {
            throw new RuntimeException('Unable to load the SSO user profile.');
        }
        return $profile;
    }

    /** @return array<string,mixed> */
    private function verifyJwt(string $jwt, string $expectedNonce): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new RuntimeException('SSO identity token format is invalid.');
        }
        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;
        $header = json_decode($this->base64UrlDecode($encodedHeader), true);
        $claims = json_decode($this->base64UrlDecode($encodedPayload), true);
        $signature = $this->base64UrlDecode($encodedSignature);
        if (! is_array($header) || ! is_array($claims) || ($header['alg'] ?? null) !== 'RS256' || ! is_string($header['kid'] ?? null)) {
            throw new RuntimeException('SSO identity token header is invalid.');
        }

        $key = $this->jwksKey((string) $header['kid']);
        $verified = openssl_verify($encodedHeader.'.'.$encodedPayload, $signature, $this->jwkToPem($key), OPENSSL_ALGO_SHA256);
        if ($verified !== 1) {
            throw new RuntimeException('SSO identity token signature is invalid.');
        }
        $audience = $claims['aud'] ?? [];
        $audiences = is_array($audience) ? $audience : [$audience];
        $now = time();
        if (! hash_equals((string) config('sso.issuer'), (string) ($claims['iss'] ?? ''))
            || ! in_array((string) config('sso.client_id'), $audiences, true)
            || (count($audiences) > 1 && ! hash_equals((string) config('sso.client_id'), (string) ($claims['azp'] ?? '')))
            || ! is_string($claims['sub'] ?? null)
            || (int) ($claims['exp'] ?? 0) < $now - 60
            || (isset($claims['nbf']) && (int) $claims['nbf'] > $now + 60)
            || ! hash_equals($expectedNonce, (string) ($claims['nonce'] ?? ''))) {
            throw new RuntimeException('SSO identity token claims are invalid.');
        }
        return $claims;
    }

    /** @return array<string,mixed> */
    private function jwksKey(string $kid): array
    {
        $keys = $this->jwks();
        $key = $this->findJwksKey($keys, $kid);
        if ($key !== null) {
            return $key;
        }

        // The provider may rotate its signing key before our cache expires.
        Cache::forget('sso:oidc:jwks');
        $key = $this->findJwksKey($this->jwks(), $kid);
        if ($key !== null) {
            return $key;
        }

        throw new RuntimeException('SSO signing key is unavailable.');
    }

    /** @return list<array<string,mixed>> */
    private function jwks(): array
    {
        return Cache::remember('sso:oidc:jwks', now()->addHour(), function (): array {
            $response = $this->http->acceptJson()->connectTimeout(5)->timeout(10)->retry(2, 200, throw: false)
                ->get($this->internalizeUrl((string) config('sso.jwks_uri')));
            $payload = $response->json();
            return $response->successful() && is_array($payload) && is_array($payload['keys'] ?? null) ? $payload['keys'] : [];
        });
    }

    /** @param list<array<string,mixed>> $keys
     *  @return array<string,mixed>|null
     */
    private function findJwksKey(array $keys, string $kid): ?array
    {
        foreach ($keys as $key) {
            if (is_array($key) && ($key['kid'] ?? null) === $kid && ($key['kty'] ?? null) === 'RSA') {
                return $key;
            }
        }

        return null;
    }

    private function internalApiUrl(string $path): string
    {
        return rtrim((string) config('sso.internal_api_url'), '/').'/'.ltrim($path, '/');
    }

    private function internalizeUrl(string $url): string
    {
        try {
            $original = parse_url($url);
            $issuer = parse_url((string) config('sso.issuer'));
            $internal = parse_url((string) config('sso.internal_api_url'));
            if (! is_array($original) || ! is_array($issuer) || ! is_array($internal)
                || ($original['scheme'] ?? null) !== ($issuer['scheme'] ?? null)
                || ($original['host'] ?? null) !== ($issuer['host'] ?? null)
                || ($original['port'] ?? null) !== ($issuer['port'] ?? null)) {
                return $url;
            }

            $issuerPath = rtrim((string) ($issuer['path'] ?? ''), '/');
            $path = (string) ($original['path'] ?? '/');
            if ($issuerPath !== '' && str_starts_with($path, $issuerPath.'/')) {
                $path = substr($path, strlen($issuerPath));
            }
            $base = rtrim((string) ($internal['path'] ?? ''), '/');
            $rewritten = ($internal['scheme'] ?? 'https').'://'.($internal['host'] ?? '');
            if (isset($internal['port'])) {
                $rewritten .= ':'.$internal['port'];
            }
            $rewritten .= $base.'/'.ltrim($path, '/');
            if (isset($original['query'])) {
                $rewritten .= '?'.$original['query'];
            }

            return $rewritten;
        } catch (\Throwable) {
            return $url;
        }
    }

    /** @param array<string,mixed> $jwk */
    private function jwkToPem(array $jwk): string
    {
        $n = $this->base64UrlDecode((string) ($jwk['n'] ?? ''));
        $e = $this->base64UrlDecode((string) ($jwk['e'] ?? ''));
        if ($n === '' || $e === '') {
            throw new RuntimeException('SSO signing key is malformed.');
        }
        $rsa = $this->asn1Sequence($this->asn1Integer($n).$this->asn1Integer($e));
        $algorithm = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
        $subjectPublicKeyInfo = $this->asn1Sequence($algorithm."\x03".$this->asn1Length(strlen($rsa) + 1)."\x00".$rsa);
        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($subjectPublicKeyInfo), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    private function asn1Integer(string $value): string
    {
        $value = ltrim($value, "\x00");
        if ($value === '' || (ord($value[0]) & 0x80) !== 0) {
            $value = "\x00".$value;
        }
        return "\x02".$this->asn1Length(strlen($value)).$value;
    }

    private function asn1Sequence(string $value): string { return "\x30".$this->asn1Length(strlen($value)).$value; }
    private function asn1Length(int $length): string
    {
        if ($length < 128) return chr($length);
        $bytes = ltrim(pack('N', $length), "\x00");
        return chr(0x80 | strlen($bytes)).$bytes;
    }
    private function base64UrlDecode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        return $decoded === false ? '' : $decoded;
    }
}
