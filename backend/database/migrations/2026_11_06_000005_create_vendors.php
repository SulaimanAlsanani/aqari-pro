<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('vendors',function(Blueprint $table){
   $table->id();
   $table->foreignId('user_id')->constrained()->cascadeOnDelete();
   $table->string('name',200);
   $table->string('phone',25)->nullable();
   $table->string('service',120);
   $table->string('email')->nullable();
   $table->text('notes')->nullable();
   $table->timestamps();
   $table->index(['user_id','service']);
  });
  Schema::table('maintenance_tickets',function(Blueprint $table){
   $table->foreignId('vendor_id')->nullable()->after('unit_id')->constrained('vendors')->nullOnDelete();
  });
 }
 public function down():void {
  Schema::table('maintenance_tickets',fn(Blueprint $table)=>$table->dropConstrainedForeignId('vendor_id'));
  Schema::dropIfExists('vendors');
 }
};
