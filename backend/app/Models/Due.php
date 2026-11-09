<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Due extends Model
{
    protected $fillable = ['due_date', 'amount'];
    protected $casts = ['due_date' => 'date:Y-m-d'];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }
}
