<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class AuditEvent extends Model {
 public $timestamps=false;
 const UPDATED_AT=null;
 protected $fillable=['actor_id','entity_type','entity_id','action','changed_fields'];
 protected function casts():array{return ['changed_fields'=>'array','created_at'=>'datetime'];}
}
