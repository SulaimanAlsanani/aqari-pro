<?php
namespace App\Http\Controllers\Api;

use App\Models\{Document,Due,Expense,Lease,MaintenanceTicket,Payment,Property,Tenant,Unit,Vendor};
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Authenticated, read-only, default-deny gate for synthetic browser writes.
 * This endpoint is intentionally unavailable for production installations.
 */
final class StagingE2eController extends Controller
{
    public function readiness(Request $request)
    {
        $user=$request->user();
        $email=(string)config('staging.e2e_owner_email','');
        $db=config('database.connections.mysql',[]);
        $url=parse_url((string)config('app.url'));
        $host=is_array($url)?strtolower((string)($url['host']??'')):'';
        if (!app()->environment('staging') ||
            !is_array($url) || ($url['scheme']??'')!=='https' ||
            !preg_match('/(^|[.-])stag(e|ing)([.-]|$)/D',$host) ||
            config('app.debug')!==false ||
            config('staging.e2e_write_enabled')!==true ||
            config('database.default')!=='mysql' ||
            !is_array($db) ||
            !empty($db['url']) ||
            ($db['database']??null)!=='aqari_staging' ||
            ($db['host']??null)!=='db' ||
            (string)($db['port']??'')!=='3306' ||
            ($db['username']??null)!=='aqari_staging' ||
            !filter_var($email,FILTER_VALIDATE_EMAIL) ||
            !str_starts_with(strtolower($email),'aqari-e2e-') ||
            !hash_equals(strtolower($email),strtolower((string)$user->email))) {
            abort(404);
        }

        // A shared or previously populated owner must never be used for
        // acceptance writes; even a run interrupted midway blocks reruns.
        $ownerId=$user->id;
        $hasData=Property::where('user_id',$ownerId)->exists()
            || Tenant::where('user_id',$ownerId)->exists()
            || Document::where('user_id',$ownerId)->exists()
            || Expense::whereHas('property',fn($q)=>$q->where('user_id',$ownerId))->exists()
            || MaintenanceTicket::whereHas('property',fn($q)=>$q->where('user_id',$ownerId))->exists()
            || Vendor::where('user_id',$ownerId)->exists()
            || Unit::whereHas('property',fn($q)=>$q->where('user_id',$ownerId))->exists()
            || Lease::whereHas('unit.property',fn($q)=>$q->where('user_id',$ownerId))->exists()
            || Payment::whereHas('lease.unit.property',fn($q)=>$q->where('user_id',$ownerId))->exists()
            || Due::whereHas('lease.unit.property',fn($q)=>$q->where('user_id',$ownerId))->exists();

        if ($hasData) return response()->json([
            'allowed'=>false,
            'reason'=>'Dedicated staging test owner already has records; provision a fresh account for another run.',
        ],409);

        return response()->json([
            'allowed'=>true,
            'environment'=>'staging',
            'owner_id'=>$ownerId,
        ]);
    }
}
