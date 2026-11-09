<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('owners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('name', 180);
            $table->string('phone', 30)->nullable();
            $table->timestamps();
        });

        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained()->restrictOnDelete();
            $table->string('name', 180);
            $table->string('city', 100);
            $table->string('district', 100)->nullable();
            $table->string('type', 60)->nullable();
            $table->timestamps();
            $table->index(['owner_id', 'id']);
        });

        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->string('code', 60);
            $table->string('type', 60)->nullable();
            $table->decimal('area', 12, 2)->nullable();
            $table->timestamps();
            $table->unique(['property_id', 'code']);
        });

        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained()->restrictOnDelete();
            $table->string('name', 180);
            $table->string('phone', 30)->nullable();
            $table->timestamps();
            $table->index(['owner_id', 'id']);
        });

        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('total', 15, 2);
            $table->unsignedSmallInteger('installments');
            $table->string('status', 40)->default('draft');
            $table->date('vacated_at')->nullable();
            $table->timestamps();
            $table->index(['unit_id', 'start_date', 'end_date']);
        });

        Schema::create('dues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->restrictOnDelete();
            $table->date('due_date');
            $table->decimal('amount', 15, 2);
            $table->timestamps();
            $table->index(['contract_id', 'due_date']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('due_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->date('received_at');
            $table->string('method', 60);
            $table->string('reference', 120)->nullable();
            $table->timestamps();
            $table->index(['due_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('dues');
        Schema::dropIfExists('contracts');
        Schema::dropIfExists('tenants');
        Schema::dropIfExists('units');
        Schema::dropIfExists('properties');
        Schema::dropIfExists('owners');
    }
};
