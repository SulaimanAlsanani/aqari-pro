<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Unit extends Model
{
    protected $fillable = ['code', 'type', 'area'];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
