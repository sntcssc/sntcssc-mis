<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_institute_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_institute_id')->constrained('partner_institutes')->cascadeOnDelete();
            $table->foreignId('batch_id')->constrained('batches')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['partner_institute_id', 'batch_id'], 'pi_batches_unique');
        });

        Schema::create('partner_institute_courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_institute_id')->constrained('partner_institutes')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['partner_institute_id', 'course_id'], 'pi_courses_unique');
        });

        Schema::create('partner_institute_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_institute_id')->constrained('partner_institutes')->cascadeOnDelete();
            $table->foreignId('section_id')->constrained('sections')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['partner_institute_id', 'section_id'], 'pi_sections_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_institute_sections');
        Schema::dropIfExists('partner_institute_courses');
        Schema::dropIfExists('partner_institute_batches');
    }
};
