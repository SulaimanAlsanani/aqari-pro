<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    protected $fillable = ['amount', 'received_at', 'method', 'reference'];
    protected $casts = ['received_at' => 'date:Y-m-d', 'reversed_at' => 'immutable_datetime'];

    public function events(): HasMany
    {
        return $this->hasMany(PaymentEvent::class);
    }

    public function due(): BelongsTo
    {
        return $this->belongsTo(Due::class);
    }
}
