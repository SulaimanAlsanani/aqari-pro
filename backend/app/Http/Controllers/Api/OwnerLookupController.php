<?php
namespace App\Http\Controllers\Api;

use App\Models\{Property,Unit,Tenant,Lease,Vendor};
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * Bounded, owner-scoped record picker used by the owner portal.
 * Returns display labels only; never exposes unrelated owners' rows.
 */
final class OwnerLookupController extends Controller
{
    public function index(Request $request, string $resource)
    {
        abort_unless(in_array($resource,['properties','units','tenants','leases','vendors'],true),404);
        $input=$request->validate([
            'q'=>['sometimes','nullable','string','max:80'],
        ]);
        $term=trim($input['q']??'');
        $search='%'.addcslashes($term,'%_\\').'%';
        $owner=$request->user()->id;

        $query=match($resource) {
            'properties'=>Property::where('user_id',$owner),
            'units'=>Unit::whereHas('property',fn($q)=>$q->where('user_id',$owner))
                ->with('property:id,name'),
            'tenants'=>Tenant::where('user_id',$owner),
            'leases'=>Lease::whereHas('unit.property',fn($q)=>$q->where('user_id',$owner))
                ->with(['unit:id,code,property_id','tenant:id,name']),
            'vendors'=>Vendor::where('user_id',$owner),
        };

        if ($term!=='') {
            $query->where(function($q)use($resource,$term,$search) {
                if (ctype_digit($term)) $q->whereKey((int)$term);
                else $q->whereRaw('1 = 0');

                match($resource) {
                    'properties','tenants','vendors'=>$q->orWhere('name','like',$search),
                    'units'=>$q->orWhere('code','like',$search)
                        ->orWhereHas('property',fn($p)=>$p->where('name','like',$search)),
                    'leases'=>$q->orWhereHas('tenant',fn($t)=>$t->where('name','like',$search))
                        ->orWhereHas('unit',fn($u)=>$u->where('code','like',$search)),
                };
            });
        }

        $items=$query->orderBy('id')->limit(21)->get();
        $hasMore=$items->count()>20;

        return response()->json([
            'data'=>$items->take(20)->map(fn($item)=>[
                'id'=>$item->id,
                'label'=>match($resource) {
                    'properties'=>$item->name.' — '.$item->city,
                    'units'=>$item->code.' — '.$item->property->name,
                    'tenants'=>$item->name.' — '.$item->phone,
                    'leases'=>'عقد #'.$item->id.' — '.$item->unit->code.' / '.$item->tenant->name,
                    'vendors'=>$item->name.' — '.$item->service,
                },
            ])->values(),
            'has_more'=>$hasMore,
        ]);
    }
}
