<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Lease extends Model {
 protected $fillable=['unit_id','tenant_id','renewed_from_id','starts_on','ends_on','total_amount','deposit','status'];
 protected function casts():array{return ['starts_on'=>'date:Y-m-d','ends_on'=>'date:Y-m-d','total_amount'=>'decimal:2','deposit'=>'decimal:2'];}
 public function unit(){return $this->belongsTo(Unit::class);}
 public function tenant(){return $this->belongsTo(Tenant::class);}
 public function payments(){return $this->hasMany(Payment::class);}
 public function dues(){return $this->hasMany(Due::class);}
 public function previousLease(){return $this->belongsTo(Lease::class,'renewed_from_id');}
 public function renewedLease(){return $this->hasOne(Lease::class,'renewed_from_id');}
}
