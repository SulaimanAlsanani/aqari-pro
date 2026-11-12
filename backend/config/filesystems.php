<?php
return [
 'default'=>env('FILESYSTEM_DISK','local'),
 'disks'=>[
  'local'=>['driver'=>'local','root'=>storage_path('app/private'),'serve'=>false,'throw'=>true],
  'public'=>['driver'=>'local','root'=>storage_path('app/public'),'url'=>env('APP_URL').'/storage','visibility'=>'public','throw'=>false],
  'backup'=>[
    'driver'=>'s3','key'=>env('BACKUP_S3_KEY'),'secret'=>env('BACKUP_S3_SECRET'),
    'region'=>env('BACKUP_S3_REGION','us-east-1'),'bucket'=>env('BACKUP_S3_BUCKET'),
    'endpoint'=>env('BACKUP_S3_ENDPOINT'),'use_path_style_endpoint'=>env('BACKUP_S3_PATH_STYLE',false),
    'visibility'=>'private','throw'=>true,
  ]
 ],
 'links'=>[public_path('storage')=>storage_path('app/public')]
];
