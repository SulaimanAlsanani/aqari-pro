<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Tenant extends Model
{
    protected $fillable = ['name', 'phone'];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }
}
