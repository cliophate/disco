<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SourceProvider extends Model
{
    use HasUuids;

    protected $table = 'source.providers';

    protected $fillable = ['slug', 'display_name', 'enabled', 'policy'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'policy' => 'array'];
    }
}
