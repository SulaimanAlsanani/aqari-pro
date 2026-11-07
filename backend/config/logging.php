<?php
return [
 'default'=>env('LOG_CHANNEL','stderr'),
 'channels'=>[
  'stderr'=>['driver'=>'monolog','handler'=>Monolog\Handler\StreamHandler::class,'with'=>['stream'=>'php://stderr'],'level'=>env('LOG_LEVEL','warning')],
  'single'=>['driver'=>'single','path'=>storage_path('logs/laravel.log'),'level'=>env('LOG_LEVEL','warning')],
  'stack'=>['driver'=>'stack','channels'=>['stderr'],'ignore_exceptions'=>false]
 ]
];
