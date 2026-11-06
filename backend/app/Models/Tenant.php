<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Tenant extends Model {
 protected $fillable=['name','phone','email'];
 protected $hidden=[];
 public function owner(){return $this->belongsTo(User::class,'user_id');}
}
