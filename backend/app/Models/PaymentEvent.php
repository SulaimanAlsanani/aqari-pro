<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentEvent extends Model
{
    public $timestamps = false;
    protected $fillable = ['actor_user_id', 'action', 'details'];
    protected $casts = ['details' => 'array', 'created_at' => 'immutable_datetime'];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
