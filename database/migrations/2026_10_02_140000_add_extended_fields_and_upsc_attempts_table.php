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
        // 1. Extend Student Profiles with PwBD certificate & creamy layer fields
        Schema::table('student_profiles', function (Blueprint $table) {
            $table->string('creamy_layer', 10)->nullable()->after('subcaste'); // CL, NCL
            $table->string('pwbd_cert_no', 100)->nullable()->after('pwd_percentage');
            $table->date('pwbd_issued_date')->nullable()->after('pwbd_cert_no');
            $table->string('pwbd_issued_by', 150)->nullable()->after('pwbd_issued_date');
        });

        // 2. Extend Student Documents with external source file URL
        Schema::table('student_documents', function (Blueprint $table) {
            $table->string('file_url', 1000)->nullable()->after('file_path');
        });

        // 3. Create upsc_attempts table
        Schema::create('upsc_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->year('exam_year');
            $table->string('roll_number', 50)->nullable();
            $table->boolean('prelims_cleared')->default(false);
            $table->boolean('mains_cleared')->default(false);
            $table->boolean('interview_cleared')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'exam_year']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('upsc_attempts');

        Schema::table('student_documents', function (Blueprint $table) {
            $table->dropColumn('file_url');
        });

        Schema::table('student_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'creamy_layer',
                'pwbd_cert_no',
                'pwbd_issued_date',
                'pwbd_issued_by',
            ]);
        });
    }
};
