<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CanonicalFieldChoice extends Model
{
    use HasUuids;

    protected $table = 'source.canonical_field_choices';

    protected $fillable = ['entity_id', 'predicate', 'assertion_id', 'selected_by'];
}
