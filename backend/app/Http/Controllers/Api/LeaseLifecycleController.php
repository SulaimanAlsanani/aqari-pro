<?php
namespace App\Http\Controllers\Api;
use App\Models\Lease;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeaseLifecycleController extends Controller {
 private function owned(Request $request,Lease $lease):void {
  abort_unless($lease->unit()->whereHas('property',fn($q)=>$q->where('user_id',$request->user()->id))->exists(),404);
 }
 public function activate(Request $request,Lease $lease){
  $this->owned($request,$lease);
  return DB::transaction(function()use($lease){
   $locked=Lease::whereKey($lease->id)->lockForUpdate()->firstOrFail();
   if($locked->status!=='draft')throw ValidationException::withMessages(['status'=>'يمكن تفعيل العقود المسودة فقط']);
   if(!$locked->dues()->exists())throw ValidationException::withMessages(['status'=>'يجب وجود جدول استحقاقات للعقد']);
   $locked->update(['status'=>'active']);
   return response()->json($locked->refresh());
  });
 }
 public function cancel(Request $request,Lease $lease){
  $this->owned($request,$lease);
  return DB::transaction(function()use($lease){
   $locked=Lease::whereKey($lease->id)->lockForUpdate()->firstOrFail();
   if($locked->status!=='draft')throw ValidationException::withMessages(['status'=>'الإلغاء مسموح للعقد المسودة فقط']);
   if($locked->payments()->exists())throw ValidationException::withMessages(['status'=>'لا يمكن إلغاء عقد له سندات قبض؛ تتطلب التسوية مسارًا معتمدًا']);
   $locked->update(['status'=>'cancelled']);
   return response()->json($locked->refresh());
  });
 }
 public function complete(Request $request,Lease $lease){
  $this->owned($request,$lease);
  return DB::transaction(function()use($lease){
   $locked=Lease::whereKey($lease->id)->lockForUpdate()->firstOrFail();
   if($locked->status!=='active')throw ValidationException::withMessages(['status'=>'يمكن إكمال العقد النشط فقط']);
   if($locked->ends_on->toDateString()>today()->toDateString())throw ValidationException::withMessages(['ends_on'=>'لا يمكن إكمال عقد قبل تاريخ انتهائه']);
   $dueTotal=(string)$locked->dues()->sum('amount');
   $received=(string)$locked->payments()->whereNull('voided_at')->sum('amount');
   if(bccomp($received,$dueTotal,2)<0)throw ValidationException::withMessages(['status'=>'لا يمكن إكمال عقد له استحقاقات غير مسددة']);
   $locked->update(['status'=>'completed']);
   return response()->json($locked->refresh());
  });
 }
}
