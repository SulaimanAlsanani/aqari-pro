<?php
namespace App\Http\Controllers\Api;
use App\Models\{Property,Unit,Tenant};
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

class RecordMutationsController extends Controller{
 public function updateProperty(Request $request,Property $property){
  abort_unless($property->user_id===$request->user()->id,404);
  $data=$request->validate([
   'name'=>'sometimes|required|string|max:200',
   'city'=>'sometimes|required|string|max:100',
   'district'=>'sometimes|nullable|string|max:100',
   'type'=>'sometimes|required|in:residential,commercial,mixed',
   'address'=>'sometimes|nullable|string|max:2000'
  ]);
  $property->update($data);
  return response()->json($property->refresh());
 }
 public function updateUnit(Request $request,Unit $unit){
  abort_unless($unit->property()->where('user_id',$request->user()->id)->exists(),404);
  $data=$request->validate([
   'code'=>['sometimes','required','string','max:50',Rule::unique('units','code')->where('property_id',$unit->property_id)->ignore($unit->id)],
   'type'=>'sometimes|required|string|max:50',
   'floor'=>'sometimes|nullable|string|max:50',
   'area'=>'sometimes|nullable|numeric|min:0|max:999999'
  ]);
  $unit->update($data);
  return response()->json($unit->refresh());
 }
 public function updateTenant(Request $request,Tenant $tenant){
  abort_unless($tenant->user_id===$request->user()->id,404);
  $data=$request->validate([
   'name'=>'sometimes|required|string|max:200',
   'phone'=>'sometimes|required|string|max:25',
   'email'=>'sometimes|nullable|email|max:255'
  ]);
  $tenant->update($data);
  return response()->json($tenant->refresh());
 }
}
