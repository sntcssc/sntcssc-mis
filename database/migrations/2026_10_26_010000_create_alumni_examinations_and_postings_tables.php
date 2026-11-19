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
        // Selections in multiple examinations & multiple years
        Schema::create('alumni_examinations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alumni_id')->constrained('alumni')->cascadeOnDelete();
            $table->string('examination_name', 150);
            $table->unsignedSmallInteger('exam_year')->index();
            $table->string('service_allotted', 100)->nullable();
            $table->unsignedInteger('all_india_rank')->nullable();
            $table->unsignedInteger('state_rank')->nullable();
            $table->string('roll_number', 50)->nullable();
            $table->string('optional_subject', 100)->nullable();
            $table->string('score_or_marks', 50)->nullable();
            $table->string('category_rank', 50)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->text('remarks')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['alumni_id', 'exam_year']);
        });

        // Career Posting History Progression Timeline
        Schema::create('alumni_postings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alumni_id')->constrained('alumni')->cascadeOnDelete();
            $table->string('designation', 150);
            $table->string('department_ministry', 200)->nullable();
            $table->string('posting_city', 100)->nullable();
            $table->string('posting_district', 100)->nullable();
            $table->string('posting_state', 100)->nullable();
            $table->string('posting_country', 100)->default('India');
            $table->text('office_address')->nullable();
            $table->date('from_date')->nullable();
            $table->date('to_date')->nullable();
            $table->boolean('is_current')->default(false)->index();
            $table->string('order_number', 100)->nullable();
            $table->text('responsibilities')->nullable();
            $table->text('remarks')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['alumni_id', 'is_current']);
            $table->index(['alumni_id', 'from_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alumni_postings');
        Schema::dropIfExists('alumni_examinations');
    }
};
