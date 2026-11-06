<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::table('payments',function(Blueprint $table){
   // The key is unique within its lease; NULL preserves pre-existing receipts.
   $table->string('idempotency_key',64)->nullable();
   $table->unique(['lease_id','idempotency_key']);
  });
 }
 public function down():void {
  Schema::table('payments',function(Blueprint $table){
   $table->dropUnique(['lease_id','idempotency_key']);
   $table->dropColumn('idempotency_key');
  });
 }
};
