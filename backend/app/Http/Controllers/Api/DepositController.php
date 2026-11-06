<?php
namespace App\Http\Controllers\Api;

use App\Models\Lease;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DepositController extends Controller {
 private function owned(Request $request,Lease $lease):void {
  abort_unless($lease->unit()->whereHas('property',fn($q)=>$q->where('user_id',$request->user()->id))->exists(),404);
 }
 private function totals(Lease $lease):array {
  $movements=$lease->depositMovements()->select('kind')->selectRaw('SUM(amount) AS total')->groupBy('kind')->pluck('total','kind');
  $received=bcadd((string)($movements['receipt']??0),'0',2);
  $refunded=bcadd((string)($movements['refund']??0),'0',2);
  $deducted=bcadd((string)($movements['deduction']??0),'0',2);
  return [
   'contractual'=>bcadd((string)$lease->deposit,'0',2),
   'received'=>$received,'refunded'=>$refunded,'deducted'=>$deducted,
   'held'=>bcsub(bcsub($received,$refunded,2),$deducted,2),
   'uncollected'=>bcsub((string)$lease->deposit,$received,2)
  ];
 }
 public function show(Request $request,Lease $lease) {
  $this->owned($request,$lease);
  return response()->json([
   'lease_id'=>$lease->id,'totals'=>$this->totals($lease),
   'movements'=>$lease->depositMovements()->orderByDesc('id')->paginate(30)
  ]);
 }
 public function store(Request $request,Lease $lease) {
  $this->owned($request,$lease);
  $data=$request->validate([
   'kind'=>['required',Rule::in(['receipt','refund','deduction'])],
   'amount'=>['required','numeric','gt:0','decimal:0,2'],
   'occurred_on'=>['required','date','before_or_equal:today'],
   'reason'=>['nullable','string','max:500'],
   'reference'=>['nullable','string','max:120']
  ]);
  if($data['kind']==='deduction'&&mb_strlen(trim((string)($data['reason']??'')))<10)
   throw ValidationException::withMessages(['reason'=>'يجب توثيق سبب الخصم بعشرة أحرف على الأقل']);
  return DB::transaction(function()use($lease,$data){
   $locked=Lease::whereKey($lease->id)->lockForUpdate()->firstOrFail();
   if(in_array($locked->status,['draft','cancelled'],true))
    throw ValidationException::withMessages(['kind'=>'لا يمكن إجراء حركة تأمين على عقد مسودة أو ملغى']);
   if($data['kind']!=='receipt'&&$locked->ends_on->toDateString()>today()->toDateString())
    throw ValidationException::withMessages(['kind'=>'لا يمكن رد التأمين أو خصمه قبل انتهاء مدة العقد']);
   $totals=$this->totals($locked);
   $maximum=$data['kind']==='receipt'?$totals['uncollected']:$totals['held'];
   if(bccomp((string)$data['amount'],$maximum,2)>0)
    throw ValidationException::withMessages(['amount'=>'المبلغ يتجاوز رصيد التأمين المتاح']);
   $entry=$locked->depositMovements()->create($data);
   return response()->json(['movement'=>$entry,'totals'=>$this->totals($locked)],201);
  });
 }
}
