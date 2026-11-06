<?php
namespace App\Http\Controllers\Api;
use App\Models\{Property,Unit,Tenant,Lease,Payment};
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Carbon\CarbonImmutable;

class PortfolioController extends Controller {
 public function index(Request $request){
  $uid=$request->user()->id;
  return response()->json([
   'properties'=>Property::where('user_id',$uid)->withCount('units')->orderBy('id')->paginate(20),
   'tenants_count'=>Tenant::where('user_id',$uid)->count(),
   'leases_count'=>Lease::whereHas('unit.property',fn($q)=>$q->where('user_id',$uid))->count()
  ]);
 }
 public function storeProperty(Request $request){
  $data=$request->validate(['name'=>'required|string|max:200','city'=>'required|string|max:100','district'=>'nullable|string|max:100','type'=>'required|in:residential,commercial,mixed','address'=>'nullable|string|max:2000']);
  return response()->json($request->user()->properties()->create($data),201);
 }
 public function showProperty(Request $request,Property $property){
  abort_unless($property->user_id===$request->user()->id,404);
  return $property->load('units');
 }
 public function storeUnit(Request $request,Property $property){
  abort_unless($property->user_id===$request->user()->id,404);
  $data=$request->validate(['code'=>'required|string|max:50','type'=>'required|string|max:50','floor'=>'nullable|string|max:50','area'=>'nullable|numeric|min:0|max:999999']);
  if($property->units()->where('code',$data['code'])->exists())throw ValidationException::withMessages(['code'=>'رمز الوحدة مستخدم في هذا العقار']);
  return response()->json($property->units()->create($data),201);
 }
 public function storeTenant(Request $request){
  $data=$request->validate(['name'=>'required|string|max:200','phone'=>'required|string|max:25','email'=>'nullable|email|max:255']);
  return response()->json($request->user()->tenants()->create($data),201);
 }
 public function storeLease(Request $request){
  $data=$request->validate(['unit_id'=>'required|integer','tenant_id'=>'required|integer','starts_on'=>'required|date','ends_on'=>'required|date|after:starts_on','total_amount'=>'required|numeric|gt:0','deposit'=>'nullable|numeric|min:0','installments'=>'required|integer|in:1,2,4,12']);
  $uid=$request->user()->id;
  $unit=Unit::whereHas('property',fn($q)=>$q->where('user_id',$uid))->findOrFail($data['unit_id']);
  $tenant=Tenant::where('user_id',$uid)->findOrFail($data['tenant_id']);
  $lease=DB::transaction(function()use($unit,$tenant,$data){
   // Lock the shared unit row; prevents concurrent overlapping leases on the same unit.
   Unit::whereKey($unit->id)->lockForUpdate()->firstOrFail();
   if(Lease::where('unit_id',$unit->id)->where('starts_on','<=',$data['ends_on'])->where('ends_on','>=',$data['starts_on'])->exists())
    throw ValidationException::withMessages(['starts_on'=>'توجد مدة إيجارية متداخلة مع عقد آخر للوحدة']);
   $lease=$unit->leases()->create(['tenant_id'=>$tenant->id,'starts_on'=>$data['starts_on'],'ends_on'=>$data['ends_on'],'total_amount'=>$data['total_amount'],'deposit'=>$data['deposit']??0,'status'=>'draft']);
   $n=(int)$data['installments'];
   $cents=(int)round((float)$data['total_amount']*100);
   $base=intdiv($cents,$n);$remainder=$cents%$n;
   $start=CarbonImmutable::parse($data['starts_on']);
   $last=CarbonImmutable::parse($data['ends_on']);
   for($i=0;$i<$n;$i++){
    $dueOn=$start->addMonthsNoOverflow($i*(12/$n));
    if($dueOn->greaterThan($last))$dueOn=$last;
    $pennies=$base+($i<$remainder?1:0);
    $lease->dues()->create(['sequence'=>$i+1,'due_on'=>$dueOn->toDateString(),'amount'=>number_format($pennies/100,2,'.','')]);
   }
   return $lease;
  });
  return response()->json($lease,201);
 }
 public function storePayment(Request $request,Lease $lease){
  $uid=$request->user()->id;
  abort_unless($lease->unit()->whereHas('property',fn($q)=>$q->where('user_id',$uid))->exists(),404);
  $data=$request->validate(['amount'=>'required|numeric|gt:0','paid_on'=>'required|date','method'=>'required|in:bank_transfer,cash,card','reference'=>'nullable|string|max:120']);
  $payment=DB::transaction(function()use($lease,$data){
   $locked=Lease::whereKey($lease->id)->lockForUpdate()->firstOrFail();
   $paid=(string)$locked->payments()->sum('amount');
   if(bccomp(bcadd($paid,(string)$data['amount'],2),(string)$locked->total_amount,2)>0)
    throw ValidationException::withMessages(['amount'=>'قيمة التحصيل تتجاوز القيمة المتبقية للعقد']);
   return $locked->payments()->create($data);
  });
  return response()->json($payment,201);
 }
}
