<?php
namespace App\Http\Controllers\Api;

use App\Models\{Due,Lease};
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class AlertsController extends Controller {
 public function index(Request $request) {
  $uid=$request->user()->id;
  $today=now()->toDateString();
  $dueUntil=now()->addDays(30)->toDateString();
  $dues=Due::whereHas('lease.unit.property',fn($query)=>$query->where('user_id',$uid))
   ->whereHas('lease',fn($q)=>$q->where('status','!=','cancelled'))
   ->whereDate('due_on','<=',$dueUntil)
   ->with(['lease:id,unit_id,tenant_id,status','lease.tenant:id,name','lease.unit:id,property_id,code'])
   ->withSum(['allocations'=>fn($query)=>$query->whereHas('payment',fn($p)=>$p->whereNull('voided_at'))],'amount')
   ->orderBy('due_on')->limit(500)->get();
  $alerts=[];
  foreach($dues as $due){
   $balance=bcsub((string)$due->amount,(string)($due->allocations_sum_amount??0),2);
   if(bccomp($balance,'0.00',2)<=0)continue;
   $alerts[]=[
    'type'=>$due->due_on->toDateString()<$today?'overdue':'upcoming',
    'due_id'=>$due->id,
    'lease_id'=>$due->lease_id,
    'due_on'=>$due->due_on->toDateString(),
    'balance'=>$balance,
    'tenant'=>$due->lease?->tenant?->name,
    'unit'=>$due->lease?->unit?->code
   ];
  }
  $expiring=Lease::whereHas('unit.property',fn($q)=>$q->where('user_id',$uid))
   ->where('status','active')
   ->whereBetween('ends_on',[$today,$dueUntil])
   ->orderBy('ends_on')->limit(100)->get(['id','unit_id','ends_on']);
  return response()->json([
   'as_of'=>$today,'window_days'=>30,
   'overdue_count'=>count(array_filter($alerts,fn($row)=>$row['type']==='overdue')),
   'upcoming_count'=>count(array_filter($alerts,fn($row)=>$row['type']==='upcoming')),
   'dues'=>$alerts,
   'expiring_leases'=>$expiring
  ]);
 }
}
