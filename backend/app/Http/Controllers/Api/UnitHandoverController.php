<?php
namespace App\Http\Controllers\Api;

use App\Models\Lease;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UnitHandoverController extends Controller {
 private function owned(Request $request,Lease $lease):void {
  abort_unless($lease->unit()->whereHas('property',fn($q)=>$q->where('user_id',$request->user()->id))->exists(),404);
 }
 public function index(Request $request,Lease $lease) {
  $this->owned($request,$lease);
  return response()->json(['lease_id'=>$lease->id,'handovers'=>$lease->handovers()->orderBy('handed_over_on')->get()]);
 }
 public function store(Request $request,Lease $lease) {
  $this->owned($request,$lease);
  $data=$request->validate([
   'kind'=>['required',Rule::in(['check_in','check_out'])],
   'handed_over_on'=>['required','date_format:Y-m-d','before_or_equal:today'],
   'condition'=>['required',Rule::in(['good','needs_repair','damaged'])],
   'keys_count'=>['nullable','integer','min:0','max:999'],
   'electricity_meter'=>['nullable','numeric','min:0','decimal:0,2'],
   'water_meter'=>['nullable','numeric','min:0','decimal:0,2'],
   'notes'=>['nullable','string','max:5000']
  ]);
  return DB::transaction(function()use($lease,$data){
   $locked=Lease::whereKey($lease->id)->lockForUpdate()->firstOrFail();
   if($locked->status!=='active')
    throw ValidationException::withMessages(['kind'=>'يجب أن يكون العقد نشطًا لتسجيل محاضر التسليم']);
   if($locked->handovers()->where('kind',$data['kind'])->exists())
    throw ValidationException::withMessages(['kind'=>'محضر التسليم من هذا النوع مسجل مسبقًا']);
   if($data['kind']==='check_out'){
    $checkIn=$locked->handovers()->where('kind','check_in')->first();
    if(!$checkIn)
     throw ValidationException::withMessages(['kind'=>'يجب تسجيل استلام الوحدة أولًا']);
    if($data['handed_over_on']<$checkIn->handed_over_on->toDateString())
     throw ValidationException::withMessages(['handed_over_on'=>'لا يمكن أن يسبق تسليم الخروج تاريخ الاستلام']);
   }
   $handover=$locked->handovers()->create($data);
   return response()->json($handover,201);
  });
 }
}
