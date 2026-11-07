<?php
return [
 'name'=>env('APP_NAME','Aqari Pro'),'env'=>env('APP_ENV','production'),
 'debug'=>(bool)env('APP_DEBUG',false),'url'=>env('APP_URL','http://localhost'),
 'timezone'=>'Asia/Riyadh','locale'=>'ar','fallback_locale'=>'en','faker_locale'=>'ar_SA',
 'key'=>env('APP_KEY'),'cipher'=>'AES-256-CBC','maintenance'=>['driver'=>'file']
];
