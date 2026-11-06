<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Read-only integrity check for payment allocation records.
 * Aggregate equality alone can conceal a due allocated against another lease
 * or an overpaid installment offset by an underpaid one.
 */
class LeaseAllocationIntegrity {
 public static function isValid(int $leaseId):bool {
  $crossLease=DB::table('payment_allocations as a')
   ->join('payments as p','p.id','=','a.payment_id')
   ->join('dues as d','d.id','=','a.due_id')
   ->whereNull('p.voided_at')
   ->where(function($q)use($leaseId){
    $q->where('p.lease_id',$leaseId)->orWhere('d.lease_id',$leaseId);
   })
   ->whereColumn('p.lease_id','!=','d.lease_id')->exists();
  if($crossLease)return false;

  // A receipt must be completely distributed. Canceled receipts are excluded.
  $payments=DB::table('payments as p')
   ->leftJoin('payment_allocations as a','a.payment_id','=','p.id')
   ->where('p.lease_id',$leaseId)->whereNull('p.voided_at')
   ->groupBy('p.id','p.amount')
   ->selectRaw('p.amount AS expected, COALESCE(SUM(a.amount),0) AS actual')->get();
  foreach($payments as $payment){
   if(bccomp((string)$payment->actual,(string)$payment->expected,2)!==0)return false;
  }

  // No individual due can have more allocated than the due amount.
  $dues=DB::table('dues as d')
   ->leftJoin('payment_allocations as a','a.due_id','=','d.id')
   ->leftJoin('payments as p',function($join){
    $join->on('p.id','=','a.payment_id')->whereNull('p.voided_at');
   })
   ->where('d.lease_id',$leaseId)
   ->groupBy('d.id','d.amount')
   ->selectRaw('d.amount AS expected, COALESCE(SUM(CASE WHEN p.id IS NOT NULL THEN a.amount ELSE 0 END),0) AS actual')
   ->get();
  foreach($dues as $due){
   if(bccomp((string)$due->actual,(string)$due->expected,2)>0)return false;
  }
  return true;
 }
}
