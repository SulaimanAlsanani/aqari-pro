<?php
namespace App\Http\Controllers\Api;

use App\Models\{Document,Due,Expense,Lease,MaintenanceTicket,Payment,Property,Tenant,Unit,User,Vendor};
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

        // A browser write test must only run in an EMPTY disposable database:
        // no other users, no existing domain or financial records anywhere.
        // Owner-scoped checks alone would miss customer data belonging to others.
        $ownerId=$user->id;
        $hasData=User::whereKeyNot($ownerId)->exists()
            || Property::query()->exists()
            || Unit::query()->exists()
            || Tenant::query()->exists()
            || Lease::query()->exists()
            || Payment::query()->exists()
            || Due::query()->exists()
            || Document::query()->exists()
            || Expense::query()->exists()
            || MaintenanceTicket::query()->exists()
            || Vendor::query()->exists();

        if ($hasData) return response()->json([
            'allowed'=>false,
            'reason'=>'Staging is not an empty disposable database. Use a newly provisioned isolated database.',
        ],409);

        return response()->json([
            'allowed'=>true,
            'environment'=>'staging',
            'owner_id'=>$ownerId,
        ]);
    }
}
