<?php
namespace App\Http\Controllers\Api;
use App\Models\{Expense,Payment,Property};
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;

class FinancialReportController extends Controller{
 public function cashflow(Request $request){
  $input=$request->validate([
   'from'=>['required','date_format:Y-m-d'],
   'to'=>['required','date_format:Y-m-d','after_or_equal:from']
  ]);
  $uid=$request->user()->id;
  // Aggregation is performed in SQL; never load other owners' records.
  $income=Payment::query()
   ->join('leases','leases.id','=','payments.lease_id')
   ->join('units','units.id','=','leases.unit_id')
   ->join('properties','properties.id','=','units.property_id')
   ->where('properties.user_id',$uid)
   ->whereBetween('payments.paid_on',[$input['from'],$input['to']])
   ->selectRaw('units.property_id AS property_id, SUM(payments.amount) AS amount')
   ->groupBy('units.property_id')->pluck('amount','property_id');
  $expenses=Expense::query()
   ->join('properties','properties.id','=','expenses.property_id')
   ->where('properties.user_id',$uid)
   ->whereBetween('expenses.incurred_on',[$input['from'],$input['to']])
   ->selectRaw('expenses.property_id AS property_id, SUM(expenses.amount) AS amount')
   ->groupBy('expenses.property_id')->pluck('amount','property_id');
  $rows=Property::where('user_id',$uid)->orderBy('id')->get(['id','name','city'])->map(function(Property $p)use($income,$expenses){
   $received=bcadd((string)($income[$p->id]??0),'0',2);
   $spent=bcadd((string)($expenses[$p->id]??0),'0',2);
   return ['property_id'=>$p->id,'name'=>$p->name,'city'=>$p->city,'received'=>$received,'expenses'=>$spent,'net'=>bcsub($received,$spent,2)];
  });
  $received='0.00';$spent='0.00';
  foreach($rows as $row){
   $received=bcadd($received,$row['received'],2);
   $spent=bcadd($spent,$row['expenses'],2);
  }
  return response()->json([
   'from'=>$input['from'],'to'=>$input['to'],
   'totals'=>['received'=>$received,'expenses'=>$spent,'net'=>bcsub($received,$spent,2)],
   'properties'=>$rows
  ]);
 }
}
