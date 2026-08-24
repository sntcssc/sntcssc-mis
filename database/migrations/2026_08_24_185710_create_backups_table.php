<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('filename', 255);
            $table->string('disk', 50)->default('local');
            $table->string('path', 500);
            $table->string('type', 50)->default('database_only'); // 'database_only', 'full_with_media'
            $table->string('db_driver', 50)->default('sqlite');
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->unsignedInteger('tables_count')->default(0);
            $table->unsignedInteger('records_count')->default(0);
            $table->unsignedInteger('files_count')->default(0);
            $table->string('checksum', 64)->nullable();
            $table->string('trigger_type', 50)->default('manual'); // 'manual', 'scheduled', 'api', 'pre_restore'
            $table->string('status', 50)->default('completed'); // 'completed', 'failed', 'running', 'restored'
            $table->text('error_message')->nullable();
            $table->decimal('duration_seconds', 8, 2)->default(0.00);
            $table->json('metadata')->nullable();
            $table->boolean('email_sent')->default(false);
            $table->string('email_recipient', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['status', 'deleted_at']);
            $table->index('type');
            $table->index('trigger_type');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('backups');
    }
};
