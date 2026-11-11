<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained()->restrictOnDelete();
            $table->foreignId('property_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('original_name', 180);
            $table->string('storage_path', 500)->unique();
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->timestamps();
            $table->index(['owner_id', 'property_id', 'id']);
            $table->index(['owner_id', 'contract_id', 'id']);
        });

        // PostgreSQL: never allow an attachment without exactly one parent.
        DB::statement(
            'ALTER TABLE attachments ADD CONSTRAINT attachments_one_parent '.
            'CHECK ((property_id IS NOT NULL) <> (contract_id IS NOT NULL))'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
