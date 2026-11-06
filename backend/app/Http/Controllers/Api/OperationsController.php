<?php
namespace App\Http\Controllers\Api;
use App\Models\{Property,Unit,Expense,MaintenanceTicket,Due,Lease,Payment};
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class OperationsController extends Controller{
 private function ownedProperty(Request $request,Property $property):Property{
  abort_unless($property->user_id===$request->user()->id,404);
  return $property;
 }
 public function storeExpense(Request $request,Property $property){
  $this->ownedProperty($request,$property);
  $data=$request->validate([
   'category'=>'required|string|max:80','description'=>'required|string|max:300',
   'amount'=>'required|numeric|gt:0','incurred_on'=>'required|date'
  ]);
  return response()->json($property->expenses()->create($data),201);
 }
 public function storeTicket(Request $request,Property $property){
  $this->ownedProperty($request,$property);
  $data=$request->validate([
   'unit_id'=>'nullable|integer','title'=>'required|string|max:250',
   'description'=>'nullable|string|max:5000','priority'=>'required|in:low,normal,high,urgent',
   'estimated_cost'=>'nullable|numeric|min:0'
  ]);
  if(isset($data['unit_id']))abort_unless($property->units()->whereKey($data['unit_id'])->exists(),422);
  return response()->json($property->tickets()->create([
   ...$data,'status'=>'open'
  ]),201);
 }
 public function updateTicket(Request $request,MaintenanceTicket $ticket){
  $ownerId=$request->user()->id;
  abort_unless($ticket->property()->where('user_id',$ownerId)->exists(),404);
  $data=$request->validate(['status'=>'required|in:open,in_progress,resolved,cancelled']);
  $ticket->update($data);
  return response()->json($ticket);
 }
 public function dues(Request $request){
  $uid=$request->user()->id;
  return Due::whereHas('lease.unit.property',fn($q)=>$q->where('user_id',$uid))
   ->with(['lease:id,unit_id,tenant_id','lease.tenant:id,name'])
   ->orderBy('due_on')->paginate(30);
 }
 public function overview(Request $request){
  $uid=$request->user()->id;
  $propertyIds=Property::where('user_id',$uid)->select('id');
  $leaseIds=Lease::whereHas('unit.property',fn($q)=>$q->where('user_id',$uid))->select('id');
  $received=Payment::whereIn('lease_id',$leaseIds)->sum('amount');
  $expenses=Expense::whereIn('property_id',$propertyIds)->sum('amount');
  return response()->json([
   'properties'=>Property::where('user_id',$uid)->count(),
   'units'=>Unit::whereIn('property_id',$propertyIds)->count(),
   'tenants'=>\App\Models\Tenant::where('user_id',$uid)->count(),
   'received'=>(string)$received,
   'expenses'=>(string)$expenses,
   'net_cashflow'=>number_format((float)$received-(float)$expenses,2,'.',''),
   'open_tickets'=>MaintenanceTicket::whereIn('property_id',$propertyIds)->whereIn('status',['open','in_progress'])->count()
  ]);
 }
}
