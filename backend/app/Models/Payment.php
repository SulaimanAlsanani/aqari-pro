<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = ['amount', 'received_at', 'method', 'reference'];
    protected $casts = ['received_at' => 'date:Y-m-d'];

    public function due(): BelongsTo
    {
        return $this->belongsTo(Due::class);
    }
}
