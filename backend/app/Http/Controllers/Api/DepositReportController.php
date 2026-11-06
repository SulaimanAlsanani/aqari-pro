<?php
namespace App\Http\Controllers\Api;

use App\Models\{DepositMovement,Property};
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class DepositReportController extends Controller {
 public function index(Request $request) {
  $ownerId=$request->user()->id;
  // Lifetime held balances cannot be derived from a date window; aggregate all
  // deposit movements, then group by property in the same ownership scope.
  $sums=DepositMovement::query()
   ->join('leases','leases.id','=','deposit_movements.lease_id')
   ->join('units','units.id','=','leases.unit_id')
   ->join('properties','properties.id','=','units.property_id')
   ->where('properties.user_id',$ownerId)
   ->selectRaw('units.property_id AS property_id, deposit_movements.kind AS kind, SUM(deposit_movements.amount) AS amount')
   ->groupBy('units.property_id','deposit_movements.kind')
   ->get();
  $byProperty=[];
  foreach($sums as $entry){
   $byProperty[$entry->property_id][$entry->kind]=bcadd((string)$entry->amount,'0',2);
  }
  $totals=['received'=>'0.00','refunded'=>'0.00','deducted'=>'0.00','held'=>'0.00'];
  $rows=Property::where('user_id',$ownerId)->orderBy('id')->get(['id','name','city'])->map(function(Property $property)use(&$totals,$byProperty){
   $values=$byProperty[$property->id]??[];
   $received=$values['receipt']??'0.00';
   $refunded=$values['refund']??'0.00';
   $deducted=$values['deduction']??'0.00';
   $held=bcsub(bcsub($received,$refunded,2),$deducted,2);
   foreach(['received'=>$received,'refunded'=>$refunded,'deducted'=>$deducted,'held'=>$held] as $name=>$amount){
    $totals[$name]=bcadd($totals[$name],$amount,2);
   }
   return ['property_id'=>$property->id,'name'=>$property->name,'city'=>$property->city,
    'received'=>$received,'refunded'=>$refunded,'deducted'=>$deducted,'held'=>$held];
  });
  return response()->json([
   'as_of'=>now()->toDateString(),
   'accounting_basis'=>'deposit_liability_not_rental_revenue',
   'totals'=>$totals,
   'properties'=>$rows
  ]);
 }
}
