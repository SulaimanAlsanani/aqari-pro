<?php
namespace App\Http\Controllers\Api;

use App\Models\{Due,Lease};
use App\Support\ReceivablesLedger;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class AlertsController extends Controller
{
    public function index(Request $request)
    {
        $ownerId=(int)$request->user()->id;
        $today=today()->toDateString();
        $dueUntil=today()->addDays(30)->toDateString();

        // Filter outstanding installments in SQL BEFORE applying the
        // preview limit. Otherwise hundreds of already-paid installments
        // can hide the current owner's unpaid ones and distort counts.
        $base=Due::query()
            ->whereHas('lease.unit.property',fn($q)=>$q->where('user_id',$ownerId))
            ->whereHas('lease',fn($q)=>$q->where('status','!=','cancelled'))
            ->where('due_on','<=',$dueUntil);
        $paidSql='COALESCE((SELECT SUM(pa.amount) FROM payment_allocations pa '.
            'INNER JOIN payments p ON p.id = pa.payment_id '.
            'WHERE pa.due_id = dues.id AND p.voided_at IS NULL), 0)';

        // Never silently suppress a negative due from the alert feed.
        abort_if((clone $base)->whereRaw('dues.amount < '.$paidSql)->exists(),
            409,'يوجد قسط بمبلغ مسدد يتجاوز قيمته، يلزم فحص المطابقة.');

        $unpaid=(clone $base)->whereRaw('dues.amount > '.$paidSql);
        $overdueCount=(clone $unpaid)->where('due_on','<',$today)->count();
        $upcomingCount=(clone $unpaid)->where('due_on','>=',$today)->count();
        $dues=(clone $unpaid)
            ->with(['lease:id,unit_id,tenant_id,status',
                'lease.tenant:id,name','lease.unit:id,property_id,code'])
            ->withSum(['allocations'=>fn($q)=>$q->whereHas(
                'payment',fn($p)=>$p->whereNull('voided_at')
            )],'amount')
            ->orderBy('due_on')->orderBy('id')->limit(200)->get();

        $alerts=[];
        foreach($dues as $due) {
            $alerts[]=[
                'type'=>$due->due_on->toDateString()<$today?'overdue':'upcoming',
                'due_id'=>$due->id,'lease_id'=>$due->lease_id,
                'due_on'=>$due->due_on->toDateString(),
                'balance'=>ReceivablesLedger::balance($due),
                'tenant'=>$due->lease?->tenant?->name,
                'unit'=>$due->lease?->unit?->code,
            ];
        }

        $expiringQuery=Lease::whereHas('unit.property',fn($q)=>
                $q->where('user_id',$ownerId))
            ->where('status','active')
            ->where('starts_on','<=',$today)
            ->whereBetween('ends_on',[$today,$dueUntil]);
        $expiringCount=(clone $expiringQuery)->count();
        $expiring=(clone $expiringQuery)->orderBy('ends_on')->orderBy('id')
            ->limit(100)->get(['id','unit_id','ends_on']);

        return response()->json([
            'as_of'=>$today,'window_days'=>30,
            'overdue_count'=>$overdueCount,
            'upcoming_count'=>$upcomingCount,
            'dues_total'=>$overdueCount+$upcomingCount,
            'has_more_dues'=>$overdueCount+$upcomingCount>count($alerts),
            'dues'=>$alerts,
            'expiring_leases_count'=>$expiringCount,
            'has_more_expiring'=>$expiringCount>$expiring->count(),
            'expiring_leases'=>$expiring,
        ]);
    }
}
