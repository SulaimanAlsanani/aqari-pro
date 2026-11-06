<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class DamageDispute extends Model {
 protected $fillable=['objection','opened_at','status','resolution','resolution_reason','resolved_at','resolved_by'];
 protected function casts():array{return ['opened_at'=>'datetime','resolved_at'=>'datetime'];}
 public function damage(){return $this->belongsTo(HandoverDamage::class,'handover_damage_id');}
}
