<?php
namespace App\Http\Controllers\Api;

use App\Models\{Lease,Unit};
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeaseRenewalController extends Controller {
 public function store(Request $request,Lease $lease){
  abort_unless($lease->unit()->whereHas('property',fn($q)=>$q->where('user_id',$request->user()->id))->exists(),404);
  $data=$request->validate([
   'total_amount'=>['required','numeric','gt:0','decimal:0,2'],
   'deposit'=>['nullable','numeric','min:0','decimal:0,2'],
   'installments'=>['required','integer','in:1,2,4,12']
  ]);
  $renewal=DB::transaction(function()use($lease,$data){
   // Serialize renewals and ordinary lease creation by locking the unit.
   Unit::whereKey($lease->unit_id)->lockForUpdate()->firstOrFail();
   $old=Lease::whereKey($lease->id)->lockForUpdate()->firstOrFail();
   if(!in_array($old->status,['active','completed'],true))
    throw ValidationException::withMessages(['status'=>'يجب أن يكون العقد السابق نشطًا أو مكتملًا']);
   if(Lease::where('renewed_from_id',$old->id)->exists())
    throw ValidationException::withMessages(['status'=>'تم تجديد هذا العقد سابقًا']);
   $start=CarbonImmutable::parse($old->ends_on)->addDay();
   $end=$start->addYear()->subDay();
   if(Lease::where('unit_id',$old->unit_id)->where('status','!=','cancelled')
     ->whereDate('starts_on','<=',$end->toDateString())
     ->whereDate('ends_on','>=',$start->toDateString())->exists())
    throw ValidationException::withMessages(['starts_on'=>'تتداخل فترة التجديد مع عقد آخر على الوحدة']);
   $new=$old->unit->leases()->create([
    'tenant_id'=>$old->tenant_id,
    'renewed_from_id'=>$old->id,
    'starts_on'=>$start->toDateString(),
    'ends_on'=>$end->toDateString(),
    'total_amount'=>$data['total_amount'],
    'deposit'=>$data['deposit']??0,
    'status'=>'draft'
   ]);
   $count=(int)$data['installments'];
   $cents=(int)round((float)$data['total_amount']*100);
   $base=intdiv($cents,$count);
   $extra=$cents%$count;
   for($i=0;$i<$count;$i++){
    $pennies=$base+($i<$extra?1:0);
    $dueDate=$start->addMonthsNoOverflow($i*(12/$count));
    $new->dues()->create([
     'sequence'=>$i+1,'due_on'=>$dueDate->toDateString(),
     'amount'=>number_format($pennies/100,2,'.','')
    ]);
   }
   return $new;
  });
  return response()->json($renewal->load('dues'),201);
 }
}
