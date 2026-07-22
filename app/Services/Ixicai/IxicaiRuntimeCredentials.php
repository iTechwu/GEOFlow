<?php

namespace App\Services\Ixicai;

use App\Models\Admin;
use App\Models\IxicaiApiKey;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class IxicaiRuntimeCredentials
{
    public function __construct(private readonly ApiKeyCrypto $crypto) {}

    /** @return array{base_url:string,api_key:string} */
    public function forCurrentUser(): array
    {
        $admin = Auth::guard('admin')->user();
        if (! $admin instanceof Admin) {
            throw new RuntimeException('An SSO user session is required to use ixicai models.');
        }
        return $this->forAdmin($admin);
    }

    /** @return array{base_url:string,api_key:string} */
    public function forAdmin(Admin $admin): array
    {
        $key = IxicaiApiKey::query()->where('admin_id', $admin->id)->where('status', 'active')->first();
        $plainKey = $key instanceof IxicaiApiKey ? $this->crypto->decrypt((string) $key->encrypted_key) : '';
        if ($plainKey === '') {
            throw new RuntimeException('No active ixicai API key is available for this SSO user.');
        }
        return ['base_url' => (string) config('ixicai.chat_base_url'), 'api_key' => $plainKey];
    }
}
