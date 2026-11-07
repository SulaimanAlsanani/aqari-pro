<?php
namespace App\Http\Controllers\Api;

use App\Models\{Due,Lease,Payment,Tenant};
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class TenantStatementController extends Controller {
 public function show(Request $request,Tenant $tenant) {
  abort_unless($tenant->user_id===$request->user()->id,404);
  $leaseIds=Lease::where('tenant_id',$tenant->id)->where('status','!=','cancelled')
   ->whereHas('unit.property',fn($q)=>$q->where('user_id',$request->user()->id))
   ->select('id');
  $dueQuery=Due::whereIn('lease_id',$leaseIds);
  $gross=bcadd((string)(clone $dueQuery)->sum('amount'),'0',2);
  $received=bcadd((string)Payment::whereIn('lease_id',$leaseIds)->whereNull('voided_at')->sum('amount'),'0',2);
  $page=$dueQuery->with(['lease:id,unit_id,tenant_id','lease.unit:id,property_id,code'])
   ->withSum(['allocations'=>fn($q)=>$q->whereHas('payment',fn($p)=>$p->whereNull('voided_at'))],'amount')
   ->orderBy('due_on')->orderBy('id')->paginate(30);
  $today=today()->toDateString();
  $page->getCollection()->transform(function(Due $due)use($today){
   $paid=bcadd((string)($due->allocations_sum_amount??'0'),'0',2);
   $balance=bcsub((string)$due->amount,$paid,2);
   $due->setAttribute('paid_amount',$paid);
   $due->setAttribute('balance_amount',$balance);
   $due->setAttribute('payment_status',bccomp($balance,'0.00',2)===0?'paid':($due->due_on->toDateString()<$today?'overdue':'pending'));
   return $due;
  });
  return response()->json([
   'tenant'=>['id'=>$tenant->id,'name'=>$tenant->name,'phone'=>$tenant->phone],
   'totals'=>['billed'=>$gross,'received'=>$received,'outstanding'=>bcsub($gross,$received,2)],
   'dues'=>$page
  ]);
 }
}
