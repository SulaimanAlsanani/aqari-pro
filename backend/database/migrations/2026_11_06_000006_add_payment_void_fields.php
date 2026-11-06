<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::table('payments',function(Blueprint $table){
   $table->timestamp('voided_at')->nullable();
   $table->string('void_reason',500)->nullable();
   $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
   $table->index('voided_at');
  });
 }
 public function down():void {
  Schema::table('payments',function(Blueprint $table){
   $table->dropConstrainedForeignId('voided_by');
   $table->dropColumn(['voided_at','void_reason']);
  });
 }
};
