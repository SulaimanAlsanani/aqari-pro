<?php
namespace App\Http\Controllers\Api;

use App\Models\Due;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Owner-only, read-only receivables aging snapshot of active leases.
 *
 * This is a current-state report, not a historic as-of ledger. Voided
 * receipts do not reduce balances. Values stay as decimal strings.
 */
final class ReceivablesAgingController extends Controller
{
    public function index(Request $request)
    {
        $ownerId=$request->user()->id;
        $today=today();
        $todayDate=$today->toDateString();
        $totals=[
            'outstanding'=>'0.00',
            'not_overdue'=>'0.00',
            'overdue'=>'0.00',
            'days_1_30'=>'0.00',
            'days_31_60'=>'0.00',
            'days_61_90'=>'0.00',
            'days_over_90'=>'0.00',
        ];
        $counts=['outstanding_installments'=>0,'overdue_installments'=>0];
        $oldest=[];

        // lazyById prevents loading every owner's due/lease into memory.
        // The primary owner restriction applies inside the SQL query.
        $query=Due::query()
            ->whereHas('lease.unit.property',fn($q)=>$q->where('user_id',$ownerId))
            ->whereHas('lease',fn($q)=>$q->where('status','active'))
            ->with(['lease.unit.property:id,name','lease.tenant:id,name'])
            ->withSum([
                'allocations'=>fn($q)=>$q->whereHas(
                    'payment',fn($p)=>$p->whereNull('voided_at')
                )
            ],'amount');

        foreach ($query->lazyById(200) as $due) {
            $paid=bcadd((string)($due->allocations_sum_amount??'0'),'0',2);
            $balance=bcsub((string)$due->amount,$paid,2);
            if (bccomp($balance,'0.00',2)<0) {
                // Do not silently turn an overallocated installment into
                // a valid dashboard number: this requires ledger review.
                abort(409,'يوجد قسط بمبلغ مسدد يتجاوز قيمته، يلزم فحص المطابقة.');
            }
            if (bccomp($balance,'0.00',2)===0) continue;

            $counts['outstanding_installments']++;
            $totals['outstanding']=bcadd($totals['outstanding'],$balance,2);
            if (!$due->due_on->lt($today)) {
                $totals['not_overdue']=bcadd($totals['not_overdue'],$balance,2);
                continue;
            }

            $counts['overdue_installments']++;
            $totals['overdue']=bcadd($totals['overdue'],$balance,2);
            $days=(int)$due->due_on->diffInDays($today);
            $bucket=$days<=30?'days_1_30':($days<=60?'days_31_60':
                ($days<=90?'days_61_90':'days_over_90'));
            $totals[$bucket]=bcadd($totals[$bucket],$balance,2);

            // Only the oldest 20 unpaid installments are returned as
            // details; never send an unbounded tenant ledger to the portal.
            $oldest[]=[
                'due_id'=>$due->id,
                'lease_id'=>$due->lease_id,
                'property'=>$due->lease->unit->property->name,
                'unit'=>$due->lease->unit->code,
                'tenant'=>$due->lease->tenant->name,
                'due_on'=>$due->due_on->toDateString(),
                'days_overdue'=>$days,
                'outstanding'=>$balance,
            ];
            usort($oldest,static fn($a,$b)=>
                $a['due_on']<=>$b['due_on'] ?: $a['due_id']<=>$b['due_id']);
            if (count($oldest)>20) array_pop($oldest);
        }

        return response()->json([
            'as_of'=>$todayDate,
            'basis'=>'active_leases_current_state',
            'totals'=>$totals,
            'counts'=>$counts,
            'oldest_overdue'=>$oldest,
            'has_more_overdue'=>$counts['overdue_installments']>count($oldest),
        ]);
    }
}
