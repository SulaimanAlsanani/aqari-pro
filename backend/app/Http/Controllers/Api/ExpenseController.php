<?php
namespace App\Http\Controllers\Api;
use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;

class ExpenseController extends Controller {
 public function update(Request $request,Expense $expense) {
  abort_unless($expense->property()->where('user_id',$request->user()->id)->exists(),404);
  if($expense->maintenance_ticket_id)
   throw ValidationException::withMessages(['expense'=>'مصروف الصيانة مرتبط ببلاغ مغلق ولا يمكن تغييره مباشرة']);
  $input=$request->validate([
   'category'=>['sometimes','required','string','max:80'],
   'description'=>['sometimes','required','string','max:300'],
   'amount'=>['sometimes','required','numeric','gt:0','decimal:0,2'],
   'incurred_on'=>['sometimes','required','date']
  ]);
  $expense->update($input);
  return response()->json($expense->refresh());
 }
}
