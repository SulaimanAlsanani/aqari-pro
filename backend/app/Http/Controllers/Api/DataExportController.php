<?php
namespace App\Http\Controllers\Api;

use App\Models\{Property,Unit,Tenant,Lease,Due,Payment,Expense,MaintenanceTicket,Vendor};
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class DataExportController extends Controller {
 public function download(Request $request,string $resource) {
  $owner=$request->user()->id;
  $definitions=[
   'properties'=>[Property::where('user_id',$owner),['id'=>'الرقم','name'=>'العقار','city'=>'المدينة','district'=>'الحي','type'=>'النوع','address'=>'العنوان']],
   'units'=>[Unit::whereHas('property',fn($q)=>$q->where('user_id',$owner)),['id'=>'الرقم','property_id'=>'العقار','code'=>'الوحدة','type'=>'النوع','floor'=>'الطابق','area'=>'المساحة']],
   'tenants'=>[Tenant::where('user_id',$owner),['id'=>'الرقم','name'=>'المستأجر','phone'=>'الجوال','email'=>'البريد']],
   'leases'=>[Lease::whereHas('unit.property',fn($q)=>$q->where('user_id',$owner)),['id'=>'العقد','unit_id'=>'الوحدة','tenant_id'=>'المستأجر','starts_on'=>'البداية','ends_on'=>'النهاية','total_amount'=>'الإجمالي','status'=>'الحالة']],
   'dues'=>[Due::whereHas('lease.unit.property',fn($q)=>$q->where('user_id',$owner))->whereHas('lease',fn($q)=>$q->where('status','!=','cancelled')),['id'=>'القسط','lease_id'=>'العقد','due_on'=>'الاستحقاق','amount'=>'المبلغ']],
   'payments'=>[Payment::whereHas('lease.unit.property',fn($q)=>$q->where('user_id',$owner)),['id'=>'السند','lease_id'=>'العقد','amount'=>'المبلغ','paid_on'=>'السداد','method'=>'الطريقة','reference'=>'المرجع','voided_at'=>'تاريخ الإلغاء']],
   'expenses'=>[Expense::whereHas('property',fn($q)=>$q->where('user_id',$owner)),['id'=>'المصروف','property_id'=>'العقار','category'=>'التصنيف','description'=>'الوصف','amount'=>'المبلغ','incurred_on'=>'التاريخ']],
   'tickets'=>[MaintenanceTicket::whereHas('property',fn($q)=>$q->where('user_id',$owner)),['id'=>'البلاغ','property_id'=>'العقار','unit_id'=>'الوحدة','vendor_id'=>'المورد','title'=>'الوصف','status'=>'الحالة','estimated_cost'=>'المتوقعة','resolved_at'=>'تاريخ الإغلاق']],
   'vendors'=>[Vendor::where('user_id',$owner),['id'=>'المورد','name'=>'الاسم','service'=>'الخدمة','phone'=>'الجوال','email'=>'البريد']]
  ];
  abort_unless(isset($definitions[$resource]),404);
  [$query,$columns]=$definitions[$resource];
  $filename='aqari-'.$resource.'-'.now()->format('Ymd').'.csv';
  return response()->streamDownload(function()use($query,$columns){
   $output=fopen('php://output','w');
   fwrite($output,"\xEF\xBB\xBF"); // UTF-8 BOM for Arabic Excel.
   fputcsv($output,array_values($columns),',','"','');
   // Lazy batches avoid loading complete portfolios into server memory.
   foreach($query->lazyById(500) as $record){
    $row=[];
    foreach(array_keys($columns) as $field){
     $value=$record->{$field};
     if($value instanceof DateTimeInterface)$value=$value->format('Y-m-d');
     $value=(string)($value??'');
     // Spreadsheet formula injection protection for untrusted names/descriptions.
     if(preg_match('/^[\s\x{FEFF}]*[=+\-@]/u',$value))$value="'".$value;
     $row[]=$value;
    }
    fputcsv($output,$row,',','"','');
   }
   fclose($output);
  },$filename,['Content-Type'=>'text/csv; charset=UTF-8','Cache-Control'=>'no-store']);
 }
}
