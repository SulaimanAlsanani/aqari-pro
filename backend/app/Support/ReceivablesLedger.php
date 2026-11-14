<?php
namespace App\Support;

use App\Models\{Due,Property};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Shared read-only owner-scoped receivables source for API and CSV.
 * The balance is calculated from non-voided payment allocations only.
 */
final class ReceivablesLedger
{
    public static function propertyFilter(Request $request, int $ownerId): ?int
    {
        $input=$request->validate([
            'property_id'=>['sometimes','required','integer','min:1']
        ]);
        if (!isset($input['property_id'])) return null;
        $propertyId=(int)$input['property_id'];
        abort_unless(
            Property::where('user_id',$ownerId)->whereKey($propertyId)->exists(),
            404
        );
        return $propertyId;
    }

    /**
     * @return Builder<Due>
     */
    public static function query(int $ownerId, ?int $propertyId=null): Builder
    {
        return Due::query()
            ->whereHas('lease.unit.property',function($q)use($ownerId,$propertyId) {
                $q->where('user_id',$ownerId);
                if ($propertyId!==null) $q->whereKey($propertyId);
            })
            ->whereHas('lease',fn($q)=>$q->where('status','active'))
            ->with(['lease.unit.property:id,name','lease.tenant:id,name'])
            ->withSum([
                'allocations'=>fn($q)=>$q->whereHas(
                    'payment',fn($p)=>$p->whereNull('voided_at')
                )
            ],'amount');
    }

    public static function paid(Due $due): string
    {
        return bcadd((string)($due->allocations_sum_amount??'0'),'0',2);
    }

    public static function balance(Due $due): string
    {
        $balance=bcsub((string)$due->amount,self::paid($due),2);
        // Never export/report an overallocated installment as a zero balance.
        abort_if(bccomp($balance,'0.00',2)<0,409,
            'يوجد قسط بمبلغ مسدد يتجاوز قيمته، يلزم فحص المطابقة.');
        return $balance;
    }

    public static function assertNoOverallocations(Builder $query): void
    {
        // Run before starting a streamed CSV response. A 409 can then be
        // returned safely rather than after CSV headers/rows have been sent.
        $invalid=(clone $query)->whereRaw(
            'dues.amount < COALESCE((SELECT SUM(pa.amount) FROM payment_allocations pa '.
            'INNER JOIN payments p ON p.id = pa.payment_id '.
            'WHERE pa.due_id = dues.id AND p.voided_at IS NULL), 0)'
        )->exists();
        abort_if($invalid,409,'توجد أقساط بتوزيعات متجاوزة، يلزم فحص المطابقة قبل التصدير.');
    }
}
