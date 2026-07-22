<?php

namespace App\Services\Ixicai;

use App\Models\Admin;
use App\Models\IxicaiApiKey;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class IxicaiApiKeyProvisioner
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly ApiKeyCrypto $crypto,
    ) {}

    public function ensure(Admin $admin, string $accessToken): IxicaiApiKey
    {
        $existing = IxicaiApiKey::query()->where('admin_id', $admin->id)->first();
        if ($existing instanceof IxicaiApiKey && $existing->status === 'active' && $this->crypto->decrypt((string) $existing->encrypted_key) !== '') {
            return $existing;
        }

        try {
            return Cache::lock('ixicai-api-key:'.$admin->id, 30)->block(10, fn (): IxicaiApiKey => $this->provision($admin, $accessToken));
        } catch (LockTimeoutException) {
            throw new RuntimeException('Your ixicai API key is being prepared. Please retry shortly.');
        }
    }

    private function provision(Admin $admin, string $accessToken): IxicaiApiKey
    {
        $existing = IxicaiApiKey::query()->where('admin_id', $admin->id)->first();
        if ($existing instanceof IxicaiApiKey && $existing->status === 'active' && $this->crypto->decrypt((string) $existing->encrypted_key) !== '') {
            return $existing;
        }

        $tenantId = trim((string) (($admin->sso_claims ?? [])['tenant_id'] ?? ''));
        if ($tenantId === '') {
            throw new RuntimeException('Your SSO session has no selected tenant.');
        }

        $response = $this->http->acceptJson()
            ->withToken($accessToken)
            ->withHeaders(['x-current-tenant' => $tenantId])
            ->connectTimeout(5)->timeout(15)->retry(2, 250, throw: false)
            ->post((string) config('ixicai.api_url').(string) config('ixicai.provision_path'), [
                'name' => config('ixicai.default_key_name'),
                'description' => 'Managed by geo.dofe',
            ]);
        $body = $response->json();
        $data = is_array($body) && is_array($body['data'] ?? null) ? $body['data'] : $body;
        $plainKey = $this->extractKey(is_array($data) ? $data : []);
        if (! $response->successful() || $plainKey === '') {
            throw new RuntimeException('Unable to provision your ixicai API key.');
        }

        return DB::transaction(function () use ($admin, $data, $plainKey): IxicaiApiKey {
            $current = IxicaiApiKey::query()->where('admin_id', $admin->id)->lockForUpdate()->first();
            if ($current instanceof IxicaiApiKey && $current->status === 'active' && $this->crypto->decrypt((string) $current->encrypted_key) !== '') {
                return $current;
            }
            $attributes = [
                'external_key_id' => is_array($data) ? (string) ($data['id'] ?? '') : '',
                'encrypted_key' => $this->crypto->encrypt($plainKey),
                'key_hash' => hash('sha256', $plainKey),
                'key_prefix' => substr($plainKey, 0, 12),
                'status' => 'active',
                'provisioned_at' => now(),
                'last_error' => null,
            ];
            if ($current instanceof IxicaiApiKey) {
                $current->forceFill($attributes)->save();
                return $current;
            }
            return IxicaiApiKey::query()->create($attributes + ['admin_id' => $admin->id]);
        });
    }

    /** @param array<string,mixed> $data */
    private function extractKey(array $data): string
    {
        foreach (['key', 'apiKey', 'fullKey', 'api_key'] as $field) {
            if (is_string($data[$field] ?? null) && trim($data[$field]) !== '') {
                return trim($data[$field]);
            }
        }
        return '';
    }
}
