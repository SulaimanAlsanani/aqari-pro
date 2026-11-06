<?php
namespace App\Http\Controllers\Api;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
class VendorController extends Controller {
 public function index(Request $request){
  return Vendor::where('user_id',$request->user()->id)->orderBy('name')->paginate(25);
 }
 public function store(Request $request){
  $input=$request->validate([
   'name'=>['required','string','max:200'],
   'phone'=>['nullable','string','max:25'],
   'service'=>['required','string','max:120'],
   'email'=>['nullable','email','max:255'],
   'notes'=>['nullable','string','max:3000']
  ]);
  return response()->json($request->user()->vendors()->create($input),201);
 }
 public function update(Request $request,Vendor $vendor){
  abort_unless($vendor->user_id===$request->user()->id,404);
  $input=$request->validate([
   'name'=>['sometimes','required','string','max:200'],
   'phone'=>['sometimes','nullable','string','max:25'],
   'service'=>['sometimes','required','string','max:120'],
   'email'=>['sometimes','nullable','email','max:255'],
   'notes'=>['sometimes','nullable','string','max:3000']
  ]);
  $vendor->update($input);
  return response()->json($vendor->refresh());
 }
}
