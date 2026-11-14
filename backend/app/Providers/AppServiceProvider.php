<?php
namespace App\Providers;
use App\Models\{AuditEvent,Property,Unit,Tenant,Lease,Due,Payment,PaymentAllocation,Expense,MaintenanceTicket,Vendor,Document,DepositMovement,UnitHandover,HandoverDamage,DamageDispute};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class AppServiceProvider extends ServiceProvider {
 public function register():void {}
 public function boot():void {
  // Apply both account- and IP-level controls; IP-only throttling cannot stop
  // credential attacks spread across many source addresses.
  RateLimiter::for('owner-login', function(Request $request):array {
   $email=mb_strtolower(trim((string)$request->input('email','')));
   return [
    Limit::perMinute(5)->by('owner-login-email:'.hash('sha256',$email)),
    Limit::perMinute(20)->by('owner-login-ip:'.$request->ip()),
   ];
  });
  // Keep expensive financial CSV downloads separate from lookup/login/
  // document throttles. The default numeric throttle key is user-wide and
  // could block an owner's first export after using other portal features.
  RateLimiter::for('owner-receivables-csv',function(Request $request) {
   $ownerId=$request->user()?->getAuthIdentifier();
   return Limit::perMinute(5)->by(
    'owner-receivables-csv:'.($ownerId!==null?'owner:'.$ownerId:'ip:'.$request->ip())
   );
  });
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
