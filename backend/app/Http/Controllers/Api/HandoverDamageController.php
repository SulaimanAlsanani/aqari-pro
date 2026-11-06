<?php
namespace App\Http\Controllers\Api;
use App\Models\{Lease,UnitHandover,HandoverDamage};
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HandoverDamageController extends Controller {
 private function owned(Request $request,Lease $lease):void {
  abort_unless($lease->unit()->whereHas('property',fn($q)=>$q->where('user_id',$request->user()->id))->exists(),404);
 }
 public function index(Request $request,Lease $lease) {
  $this->owned($request,$lease);
  return response()->json([
   'lease_id'=>$lease->id,
   'damages'=>$lease->handovers()->where('kind','check_out')->first()?->damages()
     ->with('deduction:id,handover_damage_id,amount')->orderBy('id')->get()??[]
  ]);
 }
 public function waive(Request $request,Lease $lease,HandoverDamage $damage){
  $this->owned($request,$lease);
  $data=$request->validate(['reason'=>['required','string','min:10','max:500']]);
  return DB::transaction(function()use($lease,$damage,$data){
   $locked=Lease::whereKey($lease->id)->lockForUpdate()->firstOrFail();
   if($locked->status!=='active')
    throw ValidationException::withMessages(['status'=>'لا يمكن إعفاء التلفيات بعد إكمال العقد']);
   $ownedDamage=$locked->handovers()->where('kind','check_out')->first()?->damages()
    ->whereKey($damage->id)->first();
   if(!$ownedDamage)abort(404);
   if($ownedDamage->waived_at||$ownedDamage->deduction()->exists())
    throw ValidationException::withMessages(['damage'=>'تمت معالجة التلفيات سابقًا ولا يجوز تكرار التسوية']);
   $ownedDamage->forceFill(['waived_at'=>now(),'waiver_reason'=>$data['reason']])->save();
   return response()->json($ownedDamage->refresh());
  });
 }
 public function store(Request $request,Lease $lease) {
  $this->owned($request,$lease);
  $data=$request->validate([
   'description'=>['required','string','min:10','max:500'],
   'estimated_cost'=>['required','numeric','gt:0','decimal:0,2']
  ]);
  return DB::transaction(function()use($lease,$data){
   $locked=Lease::whereKey($lease->id)->lockForUpdate()->firstOrFail();
   if($locked->status!=='active')
    throw ValidationException::withMessages(['status'=>'لا يمكن تسجيل تلفيات إلا على عقد نشط']);
   $handover=$locked->handovers()->where('kind','check_out')->first();
   if(!$handover)throw ValidationException::withMessages(['unit_handover_id'=>'يجب تسجيل محضر تسليم الخروج أولًا']);
   if($handover->condition==='good')
    throw ValidationException::withMessages(['condition'=>'محضر الخروج يشير إلى حالة جيدة؛ لا يمكن تسجيل تلفيات دون مراجعة المحضر']);
   return response()->json($handover->damages()->create($data),201);
  });
 }
}
