<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void{
  Schema::create('dues',function(Blueprint $t){
   $t->id();$t->foreignId('lease_id')->constrained()->cascadeOnDelete();
   $t->unsignedSmallInteger('sequence');$t->date('due_on');$t->decimal('amount',14,2);
   $t->timestamps();$t->unique(['lease_id','sequence']);$t->index(['due_on','lease_id']);
  });
  Schema::create('expenses',function(Blueprint $t){
   $t->id();$t->foreignId('property_id')->constrained()->cascadeOnDelete();
   $t->string('category',80);$t->string('description',300);$t->decimal('amount',14,2);$t->date('incurred_on');
   $t->timestamps();$t->index(['property_id','incurred_on']);
  });
  Schema::create('maintenance_tickets',function(Blueprint $t){
   $t->id();$t->foreignId('property_id')->constrained()->cascadeOnDelete();
   $t->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
   $t->string('title',250);$t->text('description')->nullable();
   $t->string('priority',20)->default('normal');
   $t->string('status',25)->default('open');
   $t->decimal('estimated_cost',14,2)->default(0);
   $t->timestamps();$t->index(['property_id','status']);
  });
 }
 public function down():void{foreach(['maintenance_tickets','expenses','dues'] as $t)Schema::dropIfExists($t);}
};
