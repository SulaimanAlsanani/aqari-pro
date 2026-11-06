<?php
namespace App\Providers;
use App\Models\{AuditEvent,Property,Unit,Tenant,Lease,Due,Payment,PaymentAllocation,Expense,MaintenanceTicket,Vendor,Document,DepositMovement,UnitHandover,HandoverDamage,DamageDispute};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;
use Illuminate\Auth\Notifications\ResetPassword;

class AppServiceProvider extends ServiceProvider {
 public function register():void {}
 public function boot():void {
  ResetPassword::createUrlUsing(function($user,string $token):string {
   return rtrim(config('app.url'),'/').'/app/index.html?reset_token='.rawurlencode($token).'&email='.rawurlencode($user->getEmailForPasswordReset());
  });
  $tracked=[Property::class,Unit::class,Tenant::class,Lease::class,Due::class,
   Payment::class,PaymentAllocation::class,Expense::class,MaintenanceTicket::class,Vendor::class,Document::class,DepositMovement::class,UnitHandover::class,HandoverDamage::class,DamageDispute::class];
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
