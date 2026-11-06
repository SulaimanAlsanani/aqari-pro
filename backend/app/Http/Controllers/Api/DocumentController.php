<?php
namespace App\Http\Controllers\Api;
use App\Models\{Document,Property,Unit,Tenant,Lease,Payment,Expense,MaintenanceTicket,Due,Vendor};
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
class DocumentController extends Controller {
 private function belongsToOwner(int $ownerId,string $type,int $id):bool {
  return match($type){
   'owner'=>$id===$ownerId,
   'vendor'=>Vendor::where('user_id',$ownerId)->whereKey($id)->exists(),
   'property'=>Property::where('user_id',$ownerId)->whereKey($id)->exists(),
   'unit'=>Unit::whereHas('property',fn($q)=>$q->where('user_id',$ownerId))->whereKey($id)->exists(),
   'tenant'=>Tenant::where('user_id',$ownerId)->whereKey($id)->exists(),
   'lease'=>Lease::whereHas('unit.property',fn($q)=>$q->where('user_id',$ownerId))->whereKey($id)->exists(),
   'payment'=>Payment::whereHas('lease.unit.property',fn($q)=>$q->where('user_id',$ownerId))->whereKey($id)->exists(),
   'due'=>Due::whereHas('lease.unit.property',fn($q)=>$q->where('user_id',$ownerId))->whereKey($id)->exists(),
   'expense'=>Expense::whereHas('property',fn($q)=>$q->where('user_id',$ownerId))->whereKey($id)->exists(),
   'ticket'=>MaintenanceTicket::whereHas('property',fn($q)=>$q->where('user_id',$ownerId))->whereKey($id)->exists(),
   default=>false
  };
 }
 public function store(Request $request){
  $input=$request->validate([
   'entity_type'=>['required',Rule::in(['owner','vendor','property','unit','tenant','lease','payment','due','expense','ticket'])],
   'entity_id'=>'required|integer|min:1',
   'file'=>'required|file|max:10240|mimes:pdf,png,jpg,jpeg,webp,doc,docx,xls,xlsx,txt,csv'
  ]);
  $userId=$request->user()->id;
  abort_unless($this->belongsToOwner($userId,$input['entity_type'],(int)$input['entity_id']),404);
  $file=$request->file('file');
  $path=$file->store('owners/'.$userId.'/documents','local');
  try{
   $doc=DB::transaction(fn()=>Document::create([
    'user_id'=>$userId,'entity_type'=>$input['entity_type'],'entity_id'=>$input['entity_id'],
    'path'=>$path,'original_name'=>mb_substr(basename(str_replace('\\','/',$file->getClientOriginalName())),0,200),
    'mime'=>$file->getMimeType()?:'application/octet-stream','size'=>$file->getSize()
   ]));
  }catch(\Throwable $e){Storage::disk('local')->delete($path);throw $e;}
  return response()->json($doc,201);
 }
 public function download(Request $request,Document $document){
  abort_unless($document->user_id===$request->user()->id,404);
  abort_unless($this->belongsToOwner($request->user()->id,$document->entity_type,$document->entity_id),404);
  abort_unless(Storage::disk('local')->exists($document->path),404);
  return Storage::disk('local')->download($document->path,$document->original_name);
 }
 public function destroy(Request $request,Document $document){
  abort_unless($document->user_id===$request->user()->id,404);
  abort_unless($this->belongsToOwner($request->user()->id,$document->entity_type,$document->entity_id),404);
  $path=$document->path;
  $document->delete();
  Storage::disk('local')->delete($path);
  return response()->noContent();
 }
}
