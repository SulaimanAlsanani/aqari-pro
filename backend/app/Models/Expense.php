<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Expense extends Model {
 protected $fillable=['maintenance_ticket_id','category','description','amount','incurred_on'];
 protected function casts():array{return ['incurred_on'=>'date:Y-m-d','amount'=>'decimal:2'];}
 public function property(){return $this->belongsTo(Property::class);}
 public function maintenanceTicket(){return $this->belongsTo(MaintenanceTicket::class);}
}
