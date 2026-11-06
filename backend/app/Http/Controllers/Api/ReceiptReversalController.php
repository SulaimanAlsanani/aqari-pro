<?php
namespace App\Http\Controllers\Api;
use App\Models\{Lease,Payment};
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReceiptReversalController extends Controller{
 public function void(Request $request,Payment $payment){
  $owner=$request->user()->id;
  abort_unless($payment->lease()->whereHas('unit.property',fn($q)=>$q->where('user_id',$owner))->exists(),404);
  $data=$request->validate(['reason'=>['required','string','min:10','max:500']]);
  return DB::transaction(function()use($payment,$owner,$data){
   $lease=Lease::whereKey($payment->lease_id)->lockForUpdate()->firstOrFail();
   $receipt=Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
   if($receipt->voided_at)throw ValidationException::withMessages(['reason'=>'سند القبض ملغى سابقًا']);
   if($lease->status==='completed')throw ValidationException::withMessages(['reason'=>'يجب مراجعة إكمال العقد قبل إلغاء السند']);
   $receipt->forceFill(['voided_at'=>now(),'void_reason'=>$data['reason'],'voided_by'=>$owner])->save();
   return response()->json($receipt->refresh());
  });
 }
}
