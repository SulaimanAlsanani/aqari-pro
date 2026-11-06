<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Payment extends Model {
 protected $fillable=['amount','paid_on','method','reference'];
 protected function casts():array{return ['paid_on'=>'date:Y-m-d','amount'=>'decimal:2'];}
 public function lease(){return $this->belongsTo(Lease::class);}
}
