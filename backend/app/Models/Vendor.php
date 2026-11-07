<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Vendor extends Model {
 protected $fillable=['name','phone','service','email','notes'];
 public function owner(){return $this->belongsTo(User::class,'user_id');}
 public function tickets(){return $this->hasMany(MaintenanceTicket::class);}
}
