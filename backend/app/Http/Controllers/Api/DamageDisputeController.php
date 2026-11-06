<?php
namespace App\Http\Controllers\Api;
use App\Models\{Lease,HandoverDamage,DamageDispute};
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DamageDisputeController extends Controller {
 private function owned(Request $request,Lease $lease,HandoverDamage $damage):void {
  abort_unless($lease->unit()->whereHas('property',fn($q)=>$q->where('user_id',$request->user()->id))->exists(),404);
  abort_unless($damage->handover()->where('kind','check_out')->where('lease_id',$lease->id)->exists(),404);
 }
 public function show(Request $request,Lease $lease,HandoverDamage $damage){
  $this->owned($request,$lease,$damage);
  return response()->json(['dispute'=>$damage->dispute]);
 }
 public function open(Request $request,Lease $lease,HandoverDamage $damage){
  $this->owned($request,$lease,$damage);
  $data=$request->validate(['objection'=>['required','string','min:10','max:5000']]);
  return DB::transaction(function()use($lease,$damage,$data){
   $locked=Lease::whereKey($lease->id)->lockForUpdate()->firstOrFail();
   if($locked->status!=='active')throw ValidationException::withMessages(['status'=>'الاعتراض متاح على عقد نشط فقط']);
   $d=HandoverDamage::whereKey($damage->id)->lockForUpdate()->firstOrFail();
   if($d->waived_at)throw ValidationException::withMessages(['damage'=>'تم إعفاء التلفيات بالفعل']);
   if($d->dispute()->exists())throw ValidationException::withMessages(['damage'=>'سبق تسجيل اعتراض لهذه التلفيات']);
   return response()->json($d->dispute()->create(['objection'=>$data['objection'],'opened_at'=>now(),'status'=>'open']),201);
  });
 }
 public function resolve(Request $request,Lease $lease,HandoverDamage $damage){
  $this->owned($request,$lease,$damage);
  $data=$request->validate([
   'resolution'=>['required',Rule::in(['maintain_claim','waive_claim'])],
   'reason'=>['required','string','min:10','max:5000']
  ]);
  return DB::transaction(function()use($request,$lease,$damage,$data){
   $locked=Lease::whereKey($lease->id)->lockForUpdate()->firstOrFail();
   if($locked->status!=='active')throw ValidationException::withMessages(['status'=>'لا يمكن تسوية اعتراض بعد إكمال العقد']);
   $d=HandoverDamage::whereKey($damage->id)->lockForUpdate()->firstOrFail();
   $dispute=$d->dispute()->lockForUpdate()->first();
   if(!$dispute)abort(404);
   if($dispute->status!=='open')throw ValidationException::withMessages(['resolution'=>'الاعتراض مغلق سابقًا']);
   if($data['resolution']==='waive_claim'){
    if($d->deduction()->exists())throw ValidationException::withMessages(['resolution'=>'لا يجوز الإعفاء بعد الخصم دون عكس مالي موثق']);
    if($d->waived_at)throw ValidationException::withMessages(['resolution'=>'التلفيات معفاة سابقًا']);
    $d->forceFill(['waived_at'=>now(),'waiver_reason'=>$data['reason']])->save();
   }
   $dispute->forceFill([
    'status'=>'resolved','resolution'=>$data['resolution'],
    'resolution_reason'=>$data['reason'],'resolved_at'=>now(),'resolved_by'=>$request->user()->id
   ])->save();
   return response()->json($dispute->refresh());
  });
 }
}
