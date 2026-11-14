<?php
namespace App\Http\Controllers\Api;

use App\Models\{Lease,Property};
use App\Support\ReceivablesLedger;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Current occupancy and vacancy: a unit is occupied only when an active
 * lease covers today's date. Draft and future/expired leases are not counted.
 */
final class OccupancyReportController extends Controller
{
    public function index(Request $request)
    {
        $ownerId=(int)$request->user()->id;
        $propertyId=ReceivablesLedger::propertyFilter($request,$ownerId);
        $today=today()->toDateString();
        $expiresThrough=today()->addDays(30)->toDateString();

        $properties=Property::query()
            ->where('user_id',$ownerId)
            ->when($propertyId!==null,fn($q)=>$q->whereKey($propertyId))
            ->withCount(['units','units as occupied_units_count'=>function($q)use($today) {
                $q->whereHas('leases',fn($leases)=>$leases
                    ->where('status','active')
                    ->where('starts_on','<=',$today)
                    ->where('ends_on','>=',$today));
            }]);

        $totals=['properties'=>0,'units'=>0,'occupied'=>0,'vacant'=>0];
        $top=[];
        foreach($properties->lazyById(100) as $property) {
            $units=(int)$property->units_count;
            $occupied=(int)$property->occupied_units_count;
            $vacant=$units-$occupied;
            $totals['properties']++;
            $totals['units']+=$units;
            $totals['occupied']+=$occupied;
            $totals['vacant']+=$vacant;

            $top[]=[
                'property_id'=>$property->id,'name'=>$property->name,
                'city'=>$property->city,'units'=>$units,
                'occupied'=>$occupied,'vacant'=>$vacant,
                'occupancy_percent'=>self::percent($occupied,$units)
            ];
            usort($top,static fn($a,$b)=>($b['vacant']<=>$a['vacant'])
                ?:($b['units']<=>$a['units'])
                ?:($a['property_id']<=>$b['property_id']));
            if(count($top)>20)array_pop($top);
        }

        $expiringQuery=Lease::query()
            ->whereHas('unit.property',function($q)use($ownerId,$propertyId) {
                $q->where('user_id',$ownerId);
                if($propertyId!==null)$q->whereKey($propertyId);
            })
            ->where('status','active')
            ->where('starts_on','<=',$today)
            ->whereBetween('ends_on',[$today,$expiresThrough]);

        $expiringCount=(clone $expiringQuery)->count();
        $expiring=(clone $expiringQuery)
            ->with(['unit.property:id,name','tenant:id,name'])
            ->orderBy('ends_on')->orderBy('id')->limit(20)->get()
            ->map(static fn($lease)=>[
                'lease_id'=>$lease->id,
                'property'=>$lease->unit->property->name,
                'unit'=>$lease->unit->code,
                'tenant'=>$lease->tenant->name,
                'ends_on'=>$lease->ends_on->toDateString(),
                'days_until_end'=>(int)today()->diffInDays($lease->ends_on),
            ])->values();

        return response()->json([
            'as_of'=>$today,'expires_through'=>$expiresThrough,
            'property_id'=>$propertyId,
            'basis'=>'active_leases_covering_current_date',
            'totals'=>$totals+[
                'occupancy_percent'=>self::percent($totals['occupied'],$totals['units'])
            ],
            'by_property'=>$top,
            'has_more_properties'=>$totals['properties']>count($top),
            'expiring_30_days_count'=>$expiringCount,
            'expiring_30_days'=>$expiring,
            'has_more_expiring'=>$expiringCount>$expiring->count(),
        ]);
    }

    private static function percent(int $part,int $whole): string
    {
        if($whole===0)return '0.00';
        return bcdiv(bcmul((string)$part,'100',0),(string)$whole,2);
    }
}
