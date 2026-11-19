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
        Schema::table('courses', function (Blueprint $table) {
            $table->string('fee_type', 30)->default('monthly')->after('duration'); // 'monthly', 'one_time'
            $table->decimal('fee_reserved', 10, 2)->nullable()->after('fee'); // Concession fee for SC, ST, OBC, EWS
            $table->json('fee_structure')->nullable()->after('fee_reserved'); // Category-wise fee matrix
            $table->unsignedInteger('capacity')->nullable()->after('fee_structure'); // Course max intake / capacity (null = unlimited)
        });

        Schema::table('enrollments', function (Blueprint $table) {
            $table->string('student_id', 20)->nullable()->index()->after('user_id'); // 9-digit academic student ID for this enrollment
        });

        Schema::table('sections', function (Blueprint $table) {
            $table->unsignedBigInteger('course_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->unsignedBigInteger('course_id')->nullable(false)->change();
        });

        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropColumn('student_id');
        });

        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn(['fee_type', 'fee_reserved', 'fee_structure', 'capacity']);
        });
    }
};
