<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SourceAccount extends Model
{
    use HasUuids;

    protected $table = 'source.accounts';

    protected $fillable = [
        'provider_id', 'owner_user_id', 'external_username', 'credential_env_key', 'cursor',
        'status', 'last_success_at', 'last_error_at', 'last_error',
    ];

    protected function casts(): array
    {
        return [
            'cursor' => 'array',
            'last_success_at' => 'datetime',
            'last_error_at' => 'datetime',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(SourceProvider::class, 'provider_id');
    }
}
