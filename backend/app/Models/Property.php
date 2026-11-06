<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Property extends Model {
 protected $fillable=['name','city','district','type','address'];
 public function owner(){return $this->belongsTo(User::class,'user_id');}
 public function units(){return $this->hasMany(Unit::class);}
 public function expenses(){return $this->hasMany(Expense::class);}
 public function tickets(){return $this->hasMany(MaintenanceTicket::class);}
}
