<?php
namespace App\Http\Controllers\Api;

use App\Support\ReceivablesLedger;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Exports every outstanding installment, not just the twenty displayed in
 * the UI. Uses the exact same owner-scoped ledger as the JSON aging report.
 */
final class ReceivablesExportController extends Controller
{
    public function download(Request $request)
    {
        $owner=(int)$request->user()->id;
        $propertyId=ReceivablesLedger::propertyFilter($request,$owner);
        $query=ReceivablesLedger::query($owner,$propertyId);

        // Detect known ledger corruption BEFORE opening the CSV stream so
        // clients receive HTTP 409 rather than a truncated 200 CSV file.
        ReceivablesLedger::assertNoOverallocations($query);

        $asOf=today();
        $filename='aqari-receivables-'.$asOf->format('Ymd').
            ($propertyId===null?'':'-property-'.$propertyId).'.csv';

        return response()->streamDownload(
            static function()use($query,$asOf) {
                $output=fopen('php://output','w');
                if ($output===false) return;
                fwrite($output,"\xEF\xBB\xBF"); // UTF-8 BOM for Arabic Excel.
                fputcsv($output,[
                    'تاريخ التقرير','رقم القسط','رقم العقد','العقار',
                    'الوحدة','المستأجر','تاريخ الاستحقاق',
                    'قيمة القسط','المسدد غير الملغى','المتبقي',
                    'أيام التأخير','فئة التأخير'
                ],',','"','');

                foreach ((clone $query)->lazyById(200) as $due) {
                    $balance=ReceivablesLedger::balance($due);
                    if (bccomp($balance,'0.00',2)===0) continue;
                    $late=$due->due_on->lt($asOf);
                    $days=$late?(int)$due->due_on->diffInDays($asOf):0;
                    $band=!$late?'غير متأخر':($days<=30?'1–30 يومًا':
                        ($days<=60?'31–60 يومًا':($days<=90?'61–90 يومًا':'أكثر من 90 يومًا')));
                    $row=[
                        $asOf->toDateString(),$due->id,$due->lease_id,
                        $due->lease->unit->property->name,
                        $due->lease->unit->code,
                        $due->lease->tenant->name,
                        $due->due_on->toDateString(),
                        $due->amount,ReceivablesLedger::paid($due),$balance,
                        $days,$band
                    ];
                    // Escape formula prefixes even after leading whitespace.
                    // User-controlled names never become executable formulas.
                    $safe=array_map(static function($value): string {
                        $value=(string)($value??'');
                        return preg_match('/^[\s\x{FEFF}]*[=+\-@]/u',$value)
                            ? "'".$value : $value;
                    },$row);
                    fputcsv($output,$safe,',','"','');
                }
                fclose($output);
            },
            $filename,
            [
                'Content-Type'=>'text/csv; charset=UTF-8',
                'Cache-Control'=>'private, no-store, max-age=0',
                'X-Content-Type-Options'=>'nosniff',
            ]
        );
    }
}
