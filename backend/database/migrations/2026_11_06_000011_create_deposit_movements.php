<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('deposit_movements',function(Blueprint $t){
   $t->id();
   $t->foreignId('lease_id')->constrained()->restrictOnDelete();
   $t->string('kind',20); // receipt, refund, deduction
   $t->decimal('amount',14,2);
   $t->date('occurred_on');
   $t->string('reason',500)->nullable();
   $t->string('reference',120)->nullable();
   $t->timestamps();
   $t->index(['lease_id','kind']);
  });
 }
 public function down():void {Schema::dropIfExists('deposit_movements');}
};
