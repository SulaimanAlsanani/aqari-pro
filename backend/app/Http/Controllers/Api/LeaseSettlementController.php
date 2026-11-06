<?php
namespace App\Http\Controllers\Api;

use App\Models\Lease;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class LeaseSettlementController extends Controller {
 public function show(Request $request,Lease $lease){
  abort_unless($lease->unit()->whereHas('property',fn($q)=>$q->where('user_id',$request->user()->id))->exists(),404);
  $billed=bcadd((string)$lease->dues()->sum('amount'),'0',2);
  $received=bcadd((string)$lease->payments()->whereNull('voided_at')->sum('amount'),'0',2);
  $allocated=bcadd((string)($lease->dues()->join('payment_allocations','payment_allocations.due_id','=','dues.id')
   ->join('payments','payments.id','=','payment_allocations.payment_id')
   ->whereNull('payments.voided_at')->sum('payment_allocations.amount')),'0',2);
  $movements=$lease->depositMovements()->select('kind')->selectRaw('SUM(amount) AS total')->groupBy('kind')->pluck('total','kind');
  $depositReceived=bcadd((string)($movements['receipt']??0),'0',2);
  $depositRefunded=bcadd((string)($movements['refund']??0),'0',2);
  $depositDeducted=bcadd((string)($movements['deduction']??0),'0',2);
  $held=bcsub(bcsub($depositReceived,$depositRefunded,2),$depositDeducted,2);
  $rentOutstanding=bcsub($billed,$received,2);
  $rentBalanced=bccomp($billed,(string)$lease->total_amount,2)===0
    && bccomp($allocated,$received,2)===0
    && bccomp($rentOutstanding,'0.00',2)>=0;
  $depositBalanced=bccomp($held,'0.00',2)>=0
    && bccomp($depositReceived,(string)$lease->deposit,2)<=0;
  $hasCheckIn=$lease->handovers()->where('kind','check_in')->exists();
  $hasCheckOut=$lease->handovers()->where('kind','check_out')->exists();
  $handoverComplete=!$hasCheckIn||$hasCheckOut;
  $expired=$lease->ends_on->toDateString()<=today()->toDateString();
  $flags=[];
  if(!$expired)$flags[]='LEASE_NOT_EXPIRED';
  if(!in_array($lease->status,['active','completed'],true))$flags[]='LEASE_NOT_ACTIVE';
  if(bccomp($rentOutstanding,'0.00',2)>0)$flags[]='RENT_OUTSTANDING';
  if(bccomp($held,'0.00',2)>0)$flags[]='DEPOSIT_HELD';
  if(!$rentBalanced)$flags[]='RENT_LEDGER_MISMATCH';
  if(!$depositBalanced)$flags[]='DEPOSIT_LEDGER_MISMATCH';
  if(!$handoverComplete)$flags[]='UNIT_HANDOVER_PENDING';
  return response()->json([
   'lease'=>['id'=>$lease->id,'status'=>$lease->status,'ends_on'=>$lease->ends_on->toDateString(),
    'tenant_id'=>$lease->tenant_id,'unit_id'=>$lease->unit_id],
   'rent'=>['billed'=>$billed,'received'=>$received,'allocated'=>$allocated,
    'outstanding'=>$rentOutstanding,'balanced'=>$rentBalanced],
   'deposit'=>['contractual'=>bcadd((string)$lease->deposit,'0',2),
    'received'=>$depositReceived,'refunded'=>$depositRefunded,
    'deducted'=>$depositDeducted,'held'=>$held,'balanced'=>$depositBalanced],
   'handover'=>['check_in_recorded'=>$hasCheckIn,'check_out_recorded'=>$hasCheckOut,'complete'=>$handoverComplete],
   'review_flags'=>$flags,
   'ready_for_financial_closure'=>$expired&&$handoverComplete&&in_array($lease->status,['active','completed'],true)&&$rentBalanced&&$depositBalanced
    &&bccomp($rentOutstanding,'0.00',2)===0&&bccomp($held,'0.00',2)===0
  ]);
 }
}
