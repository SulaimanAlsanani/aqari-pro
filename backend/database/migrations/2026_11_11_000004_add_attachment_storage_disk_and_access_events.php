<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->string('storage_disk', 40)->default('local');
        });

        Schema::create('attachment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attachment_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 30);
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['attachment_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachment_events');

        Schema::table('attachments', function (Blueprint $table) {
            $table->dropColumn('storage_disk');
        });
    }
};
