<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IxicaiApiKey extends Model
{
    protected $table = 'ixicai_api_keys';

    protected $hidden = ['encrypted_key', 'key_hash'];

    protected $fillable = [
        'admin_id', 'external_key_id', 'encrypted_key', 'key_hash', 'key_prefix',
        'status', 'provisioned_at', 'last_error',
    ];

    protected function casts(): array
    {
        return ['provisioned_at' => 'datetime'];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
