<?php
namespace App\Providers;
use App\Models\{AuditEvent,Property,Unit,Tenant,Lease,Due,Payment,PaymentAllocation,Expense,MaintenanceTicket,Document};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider {
 public function register():void {}
 public function boot():void {
  $tracked=[Property::class,Unit::class,Tenant::class,Lease::class,Due::class,
   Payment::class,PaymentAllocation::class,Expense::class,MaintenanceTicket::class,Vendor::class,Document::class];
  foreach($tracked as $modelClass){
   foreach(['created','updated','deleted'] as $action){
    $modelClass::{$action}(function(Model $model)use($action):void{
     $actor=auth()->id();
     if(!$actor)return;
     $changed=array_values(array_diff(array_keys($model->getChanges()),['created_at','updated_at']));
     AuditEvent::create([
      'actor_id'=>$actor,
      'entity_type'=>class_basename($model),
      'entity_id'=>$model->getKey(),
      'action'=>$action,
      'changed_fields'=>$action==='updated'?$changed:[]
     ]);
    });
   }
  }
 }
}
