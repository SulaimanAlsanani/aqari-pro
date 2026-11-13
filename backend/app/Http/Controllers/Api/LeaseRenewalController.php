<?php
namespace App\Http\Controllers\Api;

use App\Models\{Lease,Unit};
use Carbon\CarbonImmutable;
use App\Support\RentInstallmentSchedule;
use App\Support\LeaseDueDates;
use InvalidArgumentException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeaseRenewalController extends Controller {
 public function store(Request $request,Lease $lease){
  abort_unless($lease->unit()->whereHas('property',fn($q)=>$q->where('user_id',$request->user()->id))->exists(),404);
  $data=$request->validate([
   'total_amount'=>['required','numeric','gt:0','decimal:0,2','max:9999999999.99'],
   'deposit'=>['nullable','numeric','min:0','decimal:0,2','max:9999999999.99'],
   'installments'=>['required','integer','in:1,2,4,12']
  ]);
  try {
   $installmentAmounts=RentInstallmentSchedule::amounts($data['total_amount'],(int)$data['installments']);
  } catch (InvalidArgumentException $exception) {
   throw ValidationException::withMessages(['total_amount'=>'يجب أن يكون كل قسط على الأقل 0.01 ريال، ويجب إدخال مبلغ بخانتين عشريتين كحد أقصى']);
  }
  $renewal=DB::transaction(function()use($lease,$data,$installmentAmounts){
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
   // The renewal is a full year; use the same deterministic schedule
   // validation as an ordinary lease, including month-end handling.
   $dueDates=LeaseDueDates::forTerm(
    $start->toDateString(),$end->toDateString(),count($installmentAmounts)
   );
   foreach($installmentAmounts as $i=>$amount){
    $new->dues()->create([
     'sequence'=>$i+1,'due_on'=>$dueDates[$i],'amount'=>$amount
    ]);
   }
   return $new;
  });
  return response()->json($renewal->load('dues'),201);
 }
}
