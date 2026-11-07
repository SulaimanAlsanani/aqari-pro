<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
 public function up():void {
  Schema::table('leases',function(Blueprint $table){
   $table->foreignId('renewed_from_id')->nullable()->constrained('leases')->restrictOnDelete();
   $table->unique('renewed_from_id');
  });
 }
 public function down():void {
  Schema::table('leases',function(Blueprint $table){
   $table->dropUnique(['renewed_from_id']);
   $table->dropConstrainedForeignId('renewed_from_id');
  });
 }
};
