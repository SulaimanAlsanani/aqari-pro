<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('users',function(Blueprint $t){$t->id();$t->string('name');$t->string('email')->unique();$t->timestamp('email_verified_at')->nullable();$t->string('password');$t->rememberToken();$t->timestamps();});
  Schema::create('properties',function(Blueprint $t){$t->id();$t->foreignId('user_id')->constrained()->cascadeOnDelete();$t->string('name');$t->string('city');$t->string('district')->nullable();$t->string('type',40)->default('residential');$t->text('address')->nullable();$t->timestamps();$t->index(['user_id','city']);});
  Schema::create('units',function(Blueprint $t){$t->id();$t->foreignId('property_id')->constrained()->cascadeOnDelete();$t->string('code');$t->string('type',50);$t->string('floor')->nullable();$t->decimal('area',10,2)->nullable();$t->timestamps();$t->unique(['property_id','code']);});
  Schema::create('tenants',function(Blueprint $t){$t->id();$t->foreignId('user_id')->constrained()->cascadeOnDelete();$t->string('name');$t->string('phone',25);$t->string('email')->nullable();$t->timestamps();$t->index('user_id');});
  Schema::create('leases',function(Blueprint $t){$t->id();$t->foreignId('unit_id')->constrained()->cascadeOnDelete();$t->foreignId('tenant_id')->constrained()->restrictOnDelete();$t->date('starts_on');$t->date('ends_on');$t->decimal('total_amount',14,2);$t->decimal('deposit',14,2)->default(0);$t->string('status',24)->default('draft');$t->timestamps();$t->index(['unit_id','starts_on','ends_on']);});
  Schema::create('payments',function(Blueprint $t){$t->id();$t->foreignId('lease_id')->constrained()->cascadeOnDelete();$t->decimal('amount',14,2);$t->date('paid_on');$t->string('method',40);$t->string('reference')->nullable();$t->timestamps();});
  Schema::create('documents',function(Blueprint $t){$t->id();$t->foreignId('user_id')->constrained()->cascadeOnDelete();$t->string('entity_type',30);$t->unsignedBigInteger('entity_id');$t->string('path');$t->string('original_name');$t->string('mime',100);$t->unsignedBigInteger('size');$t->timestamps();$t->index(['user_id','entity_type','entity_id']);});
  Schema::create('personal_access_tokens',function(Blueprint $t){$t->id();$t->morphs('tokenable');$t->string('name');$t->string('token',64)->unique();$t->text('abilities')->nullable();$t->timestamp('last_used_at')->nullable();$t->timestamp('expires_at')->nullable();$t->timestamps();});
 }
 public function down():void {
  foreach(['personal_access_tokens','documents','payments','leases','tenants','units','properties','users'] as $table)Schema::dropIfExists($table);
 }
};
