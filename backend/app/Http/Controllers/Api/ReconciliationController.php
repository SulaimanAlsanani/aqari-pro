<?php
namespace App\Http\Controllers\Api;

use App\Models\{Lease,Payment,PaymentAllocation,Due};
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ReconciliationController extends Controller {
 public function index(Request $request) {
  $owner=$request->user()->id;
  $leases=Lease::query()
   ->whereHas('unit.property',fn($q)=>$q->where('user_id',$owner))
   ->where('status','!=','cancelled')
   ->orderBy('id')->paginate(30);
  $leaseIds=$leases->getCollection()->pluck('id');
  $dues=Due::whereIn('lease_id',$leaseIds)->get(['id','lease_id','amount']);
  $payments=Payment::whereIn('lease_id',$leaseIds)->whereNull('voided_at')->get(['id','lease_id','amount']);
  $paymentIds=$payments->pluck('id');
  $allocations=PaymentAllocation::whereIn('payment_id',$paymentIds)->get(['payment_id','due_id','amount']);
  $dueById=$dues->keyBy('id');
  $paymentById=$payments->keyBy('id');
  $leaseDueAmounts=[];
  $leasePaidAmounts=[];
  $leaseAllocatedAmounts=[];
  $issues=[];
  foreach($dues as $due) {
   $leaseDueAmounts[$due->lease_id]=bcadd($leaseDueAmounts[$due->lease_id]??'0.00',(string)$due->amount,2);
  }
  foreach($payments as $payment) {
   $leasePaidAmounts[$payment->lease_id]=bcadd($leasePaidAmounts[$payment->lease_id]??'0.00',(string)$payment->amount,2);
  }
  $dueAllocationAmounts=[];
  $paymentAllocationAmounts=[];
  foreach($allocations as $allocation) {
   $payment=$paymentById->get($allocation->payment_id);
   $due=$dueById->get($allocation->due_id);
   if(!$payment||!$due||$due->lease_id!==$payment->lease_id) {
    // This should not happen: flag mismatched or missing installment links.
    $issues[]=['code'=>'INVALID_ALLOCATION','payment_id'=>$allocation->payment_id,'due_id'=>$allocation->due_id];
    continue;
   }
   $leaseId=$payment->lease_id;
   $leaseAllocatedAmounts[$leaseId]=bcadd($leaseAllocatedAmounts[$leaseId]??'0.00',(string)$allocation->amount,2);
   $dueAllocationAmounts[$due->id]=bcadd($dueAllocationAmounts[$due->id]??'0.00',(string)$allocation->amount,2);
   $paymentAllocationAmounts[$payment->id]=bcadd($paymentAllocationAmounts[$payment->id]??'0.00',(string)$allocation->amount,2);
  }
  foreach($dues as $due) {
   if(bccomp($dueAllocationAmounts[$due->id]??'0.00',(string)$due->amount,2)>0)
    $issues[]=['code'=>'OVERPAID_DUE','due_id'=>$due->id,'lease_id'=>$due->lease_id];
  }
  foreach($payments as $payment) {
   if(bccomp($paymentAllocationAmounts[$payment->id]??'0.00',(string)$payment->amount,2)!==0)
    $issues[]=['code'=>'PAYMENT_ALLOCATION_MISMATCH','payment_id'=>$payment->id,'lease_id'=>$payment->lease_id];
  }
  $rows=[];
  foreach($leases->getCollection() as $lease) {
   $billed=$leaseDueAmounts[$lease->id]??'0.00';
   $received=$leasePaidAmounts[$lease->id]??'0.00';
   $allocated=$leaseAllocatedAmounts[$lease->id]??'0.00';
   $allocationValid=\App\Services\LeaseAllocationIntegrity::isValid($lease->id);
   if(!$allocationValid)$issues[]=['code'=>'ALLOCATION_INTEGRITY_FAILED','lease_id'=>$lease->id];
   $valid=$allocationValid&&bccomp($billed,(string)$lease->total_amount,2)===0
    && bccomp($received,$allocated,2)===0
    && bccomp($received,$billed,2)<=0;
   if(!$valid)$issues[]=['code'=>'LEASE_TOTAL_MISMATCH','lease_id'=>$lease->id];
   $rows[]=['lease_id'=>$lease->id,'billed'=>$billed,'received'=>$received,
    'allocated'=>$allocated,'outstanding'=>bcsub($billed,$received,2),'balanced'=>$valid];
  }
  return response()->json([
   'data'=>$rows,'issues'=>$issues,
   'current_page'=>$leases->currentPage(),'last_page'=>$leases->lastPage(),
   'total'=>$leases->total()
  ]);
 }
}
