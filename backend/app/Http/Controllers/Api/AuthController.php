<?php
namespace App\Http\Controllers\Api;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
class AuthController extends Controller {
 public function login(Request $request){
  $credentials=$request->validate(['email'=>['required','email'],'password'=>['required','string']]);
  if(!Auth::attempt($credentials)){return response()->json(['message'=>'بيانات الدخول غير صحيحة'],422);}
  $user=Auth::user();
  $token=$user->createToken('aqari-web', ['*'], now()->addHours(12))->plainTextToken;
  return response()->json(['token'=>$token,'token_type'=>'Bearer','expires_in'=>43200,'user'=>['id'=>$user->id,'name'=>$user->name]]);
 }
 public function me(Request $request){return response()->json(['id'=>$request->user()->id,'name'=>$request->user()->name,'email'=>$request->user()->email]);}
 public function logout(Request $request){$request->user()->currentAccessToken()?->delete();return response()->json(['message'=>'تم تسجيل الخروج']);}
}
