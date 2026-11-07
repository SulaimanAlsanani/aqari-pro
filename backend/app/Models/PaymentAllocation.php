<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PaymentAllocation extends Model {
 protected $fillable=['due_id','amount'];
 protected function casts():array{return ['amount'=>'decimal:2'];}
 public function due(){return $this->belongsTo(Due::class);}
 public function payment(){return $this->belongsTo(Payment::class);}
}
