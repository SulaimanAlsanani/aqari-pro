<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class MaintenanceTicket extends Model {
 protected $fillable=['unit_id','vendor_id','title','description','priority','status','estimated_cost'];
 protected function casts():array{return ['estimated_cost'=>'decimal:2','resolved_at'=>'datetime'];}
 public function property(){return $this->belongsTo(Property::class);}
 public function unit(){return $this->belongsTo(Unit::class);}
 public function vendor(){return $this->belongsTo(Vendor::class);}
 public function expense(){return $this->hasOne(Expense::class);}
}
