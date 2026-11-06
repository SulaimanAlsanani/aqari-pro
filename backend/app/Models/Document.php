<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Document extends Model {
 protected $fillable=['entity_type','entity_id','path','original_name','mime','size'];
 protected $hidden=['path'];
}
