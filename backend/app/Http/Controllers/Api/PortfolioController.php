<?php
namespace App\Http\Controllers\Api;
use App\Models\{Property,Unit,Tenant,Lease,Payment};
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Support\RentInstallmentSchedule;
use App\Support\LeaseDueDates;
use InvalidArgumentException;

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
  $data=$request->validate(['unit_id'=>'required|integer','tenant_id'=>'required|integer','starts_on'=>'required|date_format:Y-m-d','ends_on'=>'required|date_format:Y-m-d|after:starts_on','total_amount'=>'required|numeric|gt:0|decimal:0,2|max:9999999999.99','deposit'=>'nullable|numeric|min:0|decimal:0,2|max:9999999999.99','installments'=>'required|integer|in:1,2,4,12']);
  try {
   $installmentAmounts=RentInstallmentSchedule::amounts($data['total_amount'],(int)$data['installments']);
  } catch (InvalidArgumentException $exception) {
   throw ValidationException::withMessages(['total_amount'=>'يجب أن يكون كل قسط على الأقل 0.01 ريال، ويجب إدخال مبلغ بخانتين عشريتين كحد أقصى']);
  }
  try {
   $dueDates=LeaseDueDates::forTerm($data['starts_on'],$data['ends_on'],(int)$data['installments']);
  } catch (InvalidArgumentException $exception) {
   throw ValidationException::withMessages(['installments'=>'مدة العقد أقصر من عدد الأقساط المطلوب؛ يجب تعديل المدة أو عدد الأقساط']);
  }
  $uid=$request->user()->id;
  $unit=Unit::whereHas('property',fn($q)=>$q->where('user_id',$uid))->findOrFail($data['unit_id']);
  $tenant=Tenant::where('user_id',$uid)->findOrFail($data['tenant_id']);
  $lease=DB::transaction(function()use($unit,$tenant,$data,$installmentAmounts,$dueDates){
   // Lock the shared unit row; prevents concurrent overlapping leases on the same unit.
   Unit::whereKey($unit->id)->lockForUpdate()->firstOrFail();
   if(Lease::where('unit_id',$unit->id)->where('status','!=','cancelled')->where('starts_on','<=',$data['ends_on'])->where('ends_on','>=',$data['starts_on'])->exists())
    throw ValidationException::withMessages(['starts_on'=>'توجد مدة إيجارية متداخلة مع عقد آخر للوحدة']);
   $lease=$unit->leases()->create(['tenant_id'=>$tenant->id,'starts_on'=>$data['starts_on'],'ends_on'=>$data['ends_on'],'total_amount'=>$data['total_amount'],'deposit'=>$data['deposit']??0,'status'=>'draft']);
   foreach($installmentAmounts as $i=>$amount){
    $lease->dues()->create([
     'sequence'=>$i+1,'due_on'=>$dueDates[$i],'amount'=>$amount
    ]);
   }
   return $lease;
  });
  return response()->json($lease,201);
 }
 public function storePayment(Request $request,Lease $lease){
  $uid=$request->user()->id;
  abort_unless($lease->unit()->whereHas('property',fn($q)=>$q->where('user_id',$uid))->exists(),404);
  $data=$request->validate([
   'amount'=>['required','numeric','gt:0','decimal:0,2','max:9999999999.99'],
   'paid_on'=>['required','date_format:Y-m-d'],
   'method'=>['required','in:bank_transfer,cash,card'],
   'reference'=>['nullable','string','max:120']
  ]);
  $key=$request->header('Idempotency-Key');
  if($key!==null&&!preg_match('/^[a-zA-Z0-9_-]{8,64}$/D',$key))
   throw ValidationException::withMessages(['idempotency_key'=>'مفتاح العملية يجب أن يكون من 8 إلى 64 حرفًا أو رقمًا']);
  [$payment,$replayed]=DB::transaction(function()use($lease,$data,$key){
   $locked=Lease::whereKey($lease->id)->lockForUpdate()->firstOrFail();
   if($key!==null){
    $existing=$locked->payments()->where('idempotency_key',$key)->first();
    if($existing){
     if($existing->voided_at||bccomp((string)$existing->amount,(string)$data['amount'],2)!==0
       ||$existing->paid_on->toDateString()!==$data['paid_on']
       ||$existing->method!==$data['method']
       ||($existing->reference??null)!==($data['reference']??null))
      throw ValidationException::withMessages(['idempotency_key'=>'مفتاح العملية مستخدم سابقًا لعملية مختلفة أو ملغاة']);
     return [$existing->load('allocations'),true];
    }
   }
   if($locked->status==='cancelled'||$locked->status==='completed')throw ValidationException::withMessages(['status'=>'لا يمكن التحصيل لعقد ملغى أو مكتمل']);
   $dues=$locked->dues()->withSum(['allocations'=>fn($q)=>$q->whereHas('payment',fn($p)=>$p->whereNull('voided_at'))],'amount')->orderBy('due_on')->orderBy('sequence')->get();
   $remaining=bcadd((string)$data['amount'],'0',2);
   $allocations=[];
   foreach($dues as $due){
    if(bccomp($remaining,'0.00',2)<=0)break;
    $balance=bcsub((string)$due->amount,(string)($due->allocations_sum_amount??0),2);
    if(bccomp($balance,'0.00',2)<=0)continue;
    $take=bccomp($remaining,$balance,2)>0?$balance:$remaining;
    $allocations[]=['due_id'=>$due->id,'amount'=>$take];
    $remaining=bcsub($remaining,$take,2);
   }
   if(bccomp($remaining,'0.00',2)>0)
    throw ValidationException::withMessages(['amount'=>'قيمة التحصيل تتجاوز الأقساط غير المسددة للعقد']);
   $payment=$locked->payments()->create([...$data,'idempotency_key'=>$key]);
   $payment->allocations()->createMany($allocations);
   return [$payment->load('allocations'),false];
  });
  return response()->json($payment,$replayed?200:201);
 }
}
