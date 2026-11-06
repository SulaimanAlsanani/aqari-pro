<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration{
 public function up():void{
  Schema::table('handover_damages',function(Blueprint $table){
   $table->timestamp('waived_at')->nullable();
   $table->string('waiver_reason',500)->nullable();
  });
 }
 public function down():void{
  Schema::table('handover_damages',fn(Blueprint $table)=>$table->dropColumn(['waived_at','waiver_reason']));
 }
};
