<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class HandoverDamage extends Model {
 protected $fillable=['description','estimated_cost'];
 protected function casts():array{return ['estimated_cost'=>'decimal:2','waived_at'=>'datetime'];}
 public function handover(){return $this->belongsTo(UnitHandover::class,'unit_handover_id');}
 public function dispute(){return $this->hasOne(DamageDispute::class);}
 public function deduction(){return $this->hasOne(DepositMovement::class);}
}
