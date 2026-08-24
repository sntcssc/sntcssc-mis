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
        Schema::create('cron_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('command', 255);
            $table->json('arguments')->nullable();
            $table->string('expression', 100)->default('* * * * *');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('run_in_background')->default(true);
            $table->boolean('without_overlapping')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->string('last_run_status', 50)->nullable(); // 'success', 'failed', 'running'
            $table->decimal('last_run_duration', 8, 3)->nullable(); // duration in seconds
            $table->longText('last_run_output')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['is_active', 'deleted_at']);
            $table->index('last_run_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cron_jobs');
    }
};
