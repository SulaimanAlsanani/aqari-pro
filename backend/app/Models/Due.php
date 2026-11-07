<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Due extends Model {
 protected $fillable=['sequence','due_on','amount'];
 protected function casts():array{return ['due_on'=>'date:Y-m-d','amount'=>'decimal:2'];}
 public function lease(){return $this->belongsTo(Lease::class);}
 public function allocations(){return $this->hasMany(PaymentAllocation::class);}
}
