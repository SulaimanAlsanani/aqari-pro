<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UnitHandover extends Model {
 protected $fillable=['kind','handed_over_on','condition','keys_count','electricity_meter','water_meter','notes'];
 protected function casts():array {
  return ['handed_over_on'=>'date:Y-m-d','keys_count'=>'integer','electricity_meter'=>'decimal:2','water_meter'=>'decimal:2'];
 }
 public function lease(){return $this->belongsTo(Lease::class);}
 public function damages(){return $this->hasMany(HandoverDamage::class);}
}
