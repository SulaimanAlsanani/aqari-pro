<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttachmentEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['actor_user_id', 'action'];
    protected $casts = ['created_at' => 'immutable_datetime'];

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class);
    }
}
