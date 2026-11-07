<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::table('expenses',function(Blueprint $table) {
   $table->foreignId('maintenance_ticket_id')->nullable()->unique()
     ->constrained('maintenance_tickets')->restrictOnDelete();
  });
  Schema::table('maintenance_tickets',function(Blueprint $table) {
   $table->timestamp('resolved_at')->nullable();
  });
 }
 public function down():void {
  Schema::table('expenses',fn(Blueprint $table)=>$table->dropConstrainedForeignId('maintenance_ticket_id'));
  Schema::table('maintenance_tickets',fn(Blueprint $table)=>$table->dropColumn('resolved_at'));
 }
};
