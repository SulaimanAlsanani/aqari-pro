<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::table('deposit_movements',function(Blueprint $table){
   $table->string('idempotency_key',64)->nullable();
   $table->unique(['lease_id','idempotency_key']);
  });
 }
 public function down():void {
  Schema::table('deposit_movements',function(Blueprint $table){
   $table->dropUnique(['lease_id','idempotency_key']);
   $table->dropColumn('idempotency_key');
  });
 }
};
