<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attachment extends Model
{
    protected $fillable = [
        'owner_id', 'property_id', 'contract_id',
        'original_name', 'storage_path', 'mime_type', 'size_bytes',
    ];

    // Internal filesystem paths and owner identifiers must not leak into JSON.
    protected $hidden = ['storage_path', 'owner_id'];

    protected $casts = ['size_bytes' => 'integer'];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }
}
