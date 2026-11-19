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
        Schema::create('optional_subjects', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->unique();
            $table->string('code', 50)->nullable()->unique();
            $table->string('category', 100)->default('Humanities & Social Sciences');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('max_capacity')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('optional_subject_change_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_no', 50)->unique()->index();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('current_subject', 150)->nullable();
            $table->string('requested_subject', 150);
            $table->foreignId('optional_subject_id')->nullable()->constrained('optional_subjects')->nullOnDelete();
            $table->text('reason');
            $table->string('status', 30)->default('pending')->index(); // pending, approved, rejected, cancelled
            $table->text('admin_remarks')->nullable();

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('optional_subject_change_requests');
        Schema::dropIfExists('optional_subjects');
    }
};
