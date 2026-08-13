<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProviderCredential extends Model
{
    protected $table = 'app.provider_credentials';

    protected $primaryKey = 'provider';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['provider', 'credentials', 'tested_at'];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'tested_at' => 'datetime',
        ];
    }
}
