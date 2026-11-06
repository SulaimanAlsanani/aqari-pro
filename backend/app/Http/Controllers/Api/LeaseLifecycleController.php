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
   $dueTotal=bcadd((string)$locked->dues()->sum('amount'),'0',2);
   $received=bcadd((string)$locked->payments()->whereNull('voided_at')->sum('amount'),'0',2);
   $allocated=bcadd((string)$locked->dues()
    ->join('payment_allocations','payment_allocations.due_id','=','dues.id')
    ->join('payments','payments.id','=','payment_allocations.payment_id')
    ->whereNull('payments.voided_at')->sum('payment_allocations.amount'),'0',2);
   if(bccomp($received,$dueTotal,2)!==0||bccomp($allocated,$received,2)!==0
     ||bccomp($dueTotal,(string)$locked->total_amount,2)!==0)
    throw ValidationException::withMessages(['status'=>'لا يمكن إكمال العقد قبل تسوية أقساط الإيجار ومطابقة المبالغ الموزعة']);
   $movements=$locked->depositMovements()->select('kind')
     ->selectRaw('SUM(amount) AS total')->groupBy('kind')->pluck('total','kind');
   $held=bcsub(
     bcsub((string)($movements['receipt']??'0'),(string)($movements['refund']??'0'),2),
     (string)($movements['deduction']??'0'),2
   );
   if(bccomp($held,'0.00',2)!==0)
    throw ValidationException::withMessages(['status'=>'لا يمكن إكمال العقد وهناك تأمين محتفظ به أو فرق في سجل التأمين']);
   if($locked->handovers()->where('kind','check_in')->exists()
     &&!$locked->handovers()->where('kind','check_out')->exists())
    throw ValidationException::withMessages(['status'=>'يجب تسجيل محضر تسليم الخروج قبل إكمال العقد']);
   $openDamages=$locked->handovers()->where('kind','check_out')->first()?->damages()
     ->whereNull('waived_at')->whereDoesntHave('deduction')->count()??0;
   if($openDamages>0)
    throw ValidationException::withMessages(['status'=>'يجب معالجة التلفيات المسجلة بخصم موثق أو إعفاء مسبب قبل إكمال العقد']);
   $locked->update(['status'=>'completed']);
   return response()->json($locked->refresh());
  });
 }
}
