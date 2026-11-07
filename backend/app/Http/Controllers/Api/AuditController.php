<?php
namespace App\Http\Controllers\Api;
use App\Models\AuditEvent;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
class AuditController extends Controller {
 public function index(Request $request){
  return AuditEvent::where('actor_id',$request->user()->id)
   ->orderByDesc('id')->paginate(30);
 }
}
