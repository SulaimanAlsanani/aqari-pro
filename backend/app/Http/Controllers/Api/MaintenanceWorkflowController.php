<?php
namespace App\Http\Controllers\Api;
use App\Models\MaintenanceTicket;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MaintenanceWorkflowController extends Controller {
 public function resolve(Request $request,MaintenanceTicket $ticket) {
  abort_unless($ticket->property()->where('user_id',$request->user()->id)->exists(),404);
  $data=$request->validate([
   'actual_cost'=>['required','numeric','min:0','decimal:0,2'],
   'resolved_on'=>['required','date','before_or_equal:today'],
   'description'=>['nullable','string','max:300']
  ]);
  return DB::transaction(function()use($ticket,$data){
   $locked=MaintenanceTicket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
   if(!in_array($locked->status,['open','in_progress'],true))
    throw ValidationException::withMessages(['status'=>'البلاغ مغلق أو معالج مسبقًا']);
   $locked->forceFill(['status'=>'resolved','resolved_at'=>now()])->save();
   $expense=null;
   if(bccomp((string)$data['actual_cost'],'0.00',2)>0){
    // One expense per ticket, enforced by a unique DB constraint.
    $expense=$locked->property->expenses()->create([
     'maintenance_ticket_id'=>$locked->id,
     'category'=>'maintenance',
     'description'=>($data['description']??null)?:'تكلفة معالجة بلاغ الصيانة #'.$locked->id,
     'amount'=>$data['actual_cost'],
     'incurred_on'=>$data['resolved_on']
    ]);
   }
   return response()->json(['ticket'=>$locked->refresh(),'expense'=>$expense]);
  });
 }
}
