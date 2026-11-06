<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class DepositMovement extends Model {
 protected $fillable=['kind','amount','occurred_on','reason','reference'];
 protected function casts():array{return ['amount'=>'decimal:2','occurred_on'=>'date:Y-m-d'];}
 public function lease(){return $this->belongsTo(Lease::class);}
}
