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
        Schema::create('profile_update_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_no', 50)->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('section', 50)->default('basic'); // basic, address, education, employment, other, documents
            $table->json('payload'); // All submitted fields
            $table->json('current_data')->nullable(); // Original values snapshot
            $table->json('changes')->nullable(); // Diff [field => [old => ..., new => ...]]
            $table->text('reason'); // Justification from student
            $table->string('document_proof', 500)->nullable(); // Supporting document if needed
            $table->string('status', 30)->default('pending')->index(); // pending, approved, rejected
            $table->text('admin_notes')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('profile_update_requests');
    }
};
