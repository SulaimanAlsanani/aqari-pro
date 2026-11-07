<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void{
  Schema::create('audit_events',function(Blueprint $table){
   $table->id();
   $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
   $table->string('entity_type',90);
   $table->unsignedBigInteger('entity_id');
   $table->string('action',30);
   $table->json('changed_fields')->nullable();
   $table->timestamp('created_at')->useCurrent();
   $table->index(['actor_id','created_at']);
   $table->index(['entity_type','entity_id']);
  });
 }
 public function down():void{Schema::dropIfExists('audit_events');}
};
