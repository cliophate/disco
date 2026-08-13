<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManualOperation extends Model
{
    use HasUuids;

    protected $table = 'app.manual_operations';

    protected $fillable = [
        'owner_user_id', 'operation_key', 'parameters', 'result', 'concurrency_key', 'status',
        'error_code', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'result' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }
}
