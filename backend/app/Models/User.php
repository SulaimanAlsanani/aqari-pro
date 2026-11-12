<?php
namespace App\Models;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
class User extends Authenticatable {
 use HasApiTokens,Notifiable;
 protected $fillable=['name','email','password'];
 protected $hidden=['password','remember_token','mfa_secret','mfa_pending_secret','mfa_recovery_codes'];
 protected function casts():array{return ['email_verified_at'=>'datetime','password'=>'hashed','mfa_secret'=>'encrypted','mfa_pending_secret'=>'encrypted','mfa_recovery_codes'=>'encrypted:array','mfa_enabled_at'=>'datetime','mfa_pending_at'=>'datetime'];}
 public function properties(){return $this->hasMany(Property::class);}
 public function tenants(){return $this->hasMany(Tenant::class);}
 public function vendors(){return $this->hasMany(Vendor::class);}
}
