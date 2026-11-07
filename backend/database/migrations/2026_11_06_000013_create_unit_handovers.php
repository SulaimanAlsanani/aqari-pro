<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
 public function up():void {
  Schema::create('unit_handovers',function(Blueprint $table){
   $table->id();
   $table->foreignId('lease_id')->constrained()->restrictOnDelete();
   $table->string('kind',20); // check_in or check_out
   $table->date('handed_over_on');
   $table->string('condition',30); // good, needs_repair, damaged
   $table->unsignedInteger('keys_count')->nullable();
   $table->decimal('electricity_meter',14,2)->nullable();
   $table->decimal('water_meter',14,2)->nullable();
   $table->text('notes')->nullable();
   $table->timestamps();
   $table->unique(['lease_id','kind']);
  });
 }
 public function down():void {Schema::dropIfExists('unit_handovers');}
};
