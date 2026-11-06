<?php
namespace App\Http\Controllers\Api;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PasswordResetController extends Controller {
 public function requestLink(Request $request){
  $data=$request->validate(['email'=>['required','email','max:255']]);
  // Do not expose whether the email exists. The broker handles cooldowns and expiry.
  if(app()->environment('production')&&in_array(config('mail.default'),['log','array'],true))
   return response()->json(['message'=>'خدمة استعادة كلمة المرور غير مهيأة'],503);
  try{Password::broker()->sendResetLink(['email'=>$data['email']]);}
  catch(\Throwable $error){return response()->json(['message'=>'تعذّر إرسال تعليمات الاستعادة حاليًا'],503);}
  return response()->json(['message'=>'إذا كان البريد مسجلًا، ستصلك تعليمات إعادة تعيين كلمة المرور']);
 }
 public function reset(Request $request){
  $data=$request->validate([
   'email'=>['required','email','max:255'],
   'token'=>['required','string'],
   'password'=>['required','string','min:12','confirmed']
  ]);
  $status=Password::broker()->reset($data,function(User $user,string $password){
   $user->forceFill(['password'=>Hash::make($password),'remember_token'=>Str::random(60)])->save();
   $user->tokens()->delete();
  });
  if($status!==Password::PASSWORD_RESET)
   throw ValidationException::withMessages(['token'=>'الرابط غير صحيح أو انتهت صلاحيته']);
  return response()->json(['message'=>'تم تعيين كلمة المرور الجديدة. سجل الدخول مرة أخرى']);
 }
}
