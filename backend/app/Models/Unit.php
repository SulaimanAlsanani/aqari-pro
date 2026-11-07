<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Unit extends Model {
 protected $fillable=['code','type','floor','area'];
 public function property(){return $this->belongsTo(Property::class);}
 public function leases(){return $this->hasMany(Lease::class);}
}
