<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('damage_disputes',function(Blueprint $t){
   $t->id();
   $t->foreignId('handover_damage_id')->unique()->constrained('handover_damages')->restrictOnDelete();
   $t->text('objection');
   $t->timestamp('opened_at');
   $t->string('status',16)->default('open');
   $t->string('resolution',24)->nullable();
   $t->text('resolution_reason')->nullable();
   $t->timestamp('resolved_at')->nullable();
   $t->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
   $t->timestamps();
  });
 }
 public function down():void {Schema::dropIfExists('damage_disputes');}
};
