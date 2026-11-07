<?php
namespace App\Http\Controllers\Api;
use App\Models\{Property,Unit,Tenant,Lease,Payment,Expense,MaintenanceTicket,Document};
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class RecordsController extends Controller {
 public function tenants(Request $request){
  return Tenant::where('user_id',$request->user()->id)->orderBy('id')->paginate(25);
 }
 public function units(Request $request){
  $userId=$request->user()->id;
  return Unit::whereHas('property',fn($q)=>$q->where('user_id',$userId))
   ->with('property:id,name')->orderBy('id')->paginate(25);
 }
 public function leases(Request $request){
  $userId=$request->user()->id;
  return Lease::whereHas('unit.property',fn($q)=>$q->where('user_id',$userId))
   ->with(['tenant:id,name','unit:id,property_id,code'])
   ->orderByDesc('id')->paginate(25);
 }
 public function payments(Request $request){
  $userId=$request->user()->id;
  return Payment::whereHas('lease.unit.property',fn($q)=>$q->where('user_id',$userId))
   ->orderByDesc('paid_on')->paginate(25);
 }
 public function expenses(Request $request){
  $userId=$request->user()->id;
  return Expense::whereHas('property',fn($q)=>$q->where('user_id',$userId))
   ->orderByDesc('incurred_on')->paginate(25);
 }
 public function tickets(Request $request){
  $userId=$request->user()->id;
  return MaintenanceTicket::whereHas('property',fn($q)=>$q->where('user_id',$userId))
   ->orderByDesc('id')->paginate(25);
 }
 public function documents(Request $request){
  $userId=$request->user()->id;
  $query=Document::where('user_id',$userId);
  $filters=$request->validate(['entity_type'=>'nullable|string|max:30','entity_id'=>'nullable|integer|min:1']);
  if(isset($filters['entity_type']))$query->where('entity_type',$filters['entity_type']);
  if(isset($filters['entity_id']))$query->where('entity_id',$filters['entity_id']);
  return $query->orderByDesc('id')->paginate(25);
 }
}
