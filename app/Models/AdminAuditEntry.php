<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AdminAuditEntry extends Model
{
    use HasUuids;

    protected $table = 'app.admin_audit_entries';

    public $timestamps = false;

    protected $fillable = ['owner_user_id', 'action', 'subject', 'context', 'created_at'];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
