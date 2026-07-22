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
        $request->session()->put('sso.oidc', compact('state', 'nonce', 'verifier'));

        $metadata = $this->metadata();
        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => config('sso.client_id'),
            'redirect_uri' => config('sso.redirect_uri'),
            'scope' => 'openid profile email tenant',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);

        return rtrim((string) $metadata['authorization_endpoint'], '?').'?'.$query;
    }

    /** @return array{claims: array<string,mixed>, access_token: string, expires_in: int} */
    public function complete(Request $request): array
    {
        $stored = $request->session()->pull('sso.oidc');
        $state = (string) $request->query('state', '');
        $code = (string) $request->query('code', '');
        if (! is_array($stored) || ! hash_equals((string) ($stored['state'] ?? ''), $state) || $code === '') {
            throw new RuntimeException('SSO login verification failed. Please start sign-in again.');
        }

        $metadata = $this->metadata();
        try {
            $response = $this->http->asForm()
                ->withBasicAuth((string) config('sso.client_id'), (string) config('sso.client_secret'))
                ->connectTimeout(5)->timeout(15)->retry(2, 200, throw: false)
                ->post((string) $metadata['token_endpoint'], [
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

        $claims = $this->verifyJwt($idToken, (string) ($stored['nonce'] ?? ''));
        $profile = $this->userInfo($accessToken, $metadata);
        if (($profile['sub'] ?? null) !== ($claims['sub'] ?? null)) {
            throw new RuntimeException('SSO identity response did not match the signed token.');
        }

        return [
            'claims' => array_replace($claims, $profile),
            'access_token' => $accessToken,
            'expires_in' => max(60, (int) ($payload['expires_in'] ?? config('sso.session_lifetime'))),
        ];
    }

    /** @return array<string,mixed> */
    private function metadata(): array
    {
        return Cache::remember('sso:oidc:metadata', now()->addHour(), function (): array {
            $response = $this->http->acceptJson()->connectTimeout(5)->timeout(10)->retry(2, 200, throw: false)
                ->get((string) config('sso.discovery_url'));
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
    private function userInfo(string $accessToken, array $metadata): array
    {
        $response = $this->http->acceptJson()->withToken($accessToken)->connectTimeout(5)->timeout(10)->retry(2, 200, throw: false)
            ->get((string) $metadata['userinfo_endpoint']);
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
        $keys = Cache::remember('sso:oidc:jwks', now()->addHour(), function (): array {
            $response = $this->http->acceptJson()->connectTimeout(5)->timeout(10)->retry(2, 200, throw: false)
                ->get((string) config('sso.jwks_uri'));
            $payload = $response->json();
            return $response->successful() && is_array($payload) && is_array($payload['keys'] ?? null) ? $payload['keys'] : [];
        });
        foreach ($keys as $key) {
            if (is_array($key) && ($key['kid'] ?? null) === $kid && ($key['kty'] ?? null) === 'RSA') {
                return $key;
            }
        }
        Cache::forget('sso:oidc:jwks');
        throw new RuntimeException('SSO signing key is unavailable.');
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
