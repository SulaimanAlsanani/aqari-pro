<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('handover_damages',function(Blueprint $table){
   $table->id();
   $table->foreignId('unit_handover_id')->constrained()->restrictOnDelete();
   $table->string('description',500);
   $table->decimal('estimated_cost',14,2);
   $table->timestamps();
   $table->index('unit_handover_id');
  });
  Schema::table('deposit_movements',function(Blueprint $table){
   $table->foreignId('handover_damage_id')->nullable()->unique()
    ->constrained('handover_damages')->restrictOnDelete();
  });
 }
 public function down():void {
  Schema::table('deposit_movements',fn(Blueprint $table)=>$table->dropConstrainedForeignId('handover_damage_id'));
  Schema::dropIfExists('handover_damages');
 }
};
