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
        Schema::create('partner_institutes', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();
            $table->string('website_url')->nullable();
            $table->string('email')->nullable();
            $table->string('mobile')->nullable();
            $table->string('phone')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('status', 30)->default('active'); // active, inactive, mou_expired, pending
            $table->string('official_name')->nullable();
            $table->string('official_designation')->nullable();
            $table->string('official_contact')->nullable();
            $table->string('official_email')->nullable();
            $table->text('address')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'name']);
        });

        Schema::table('faculties', function (Blueprint $table) {
            $table->foreignId('partner_institute_id')->nullable()->after('user_id')->constrained('partner_institutes')->nullOnDelete();
            $table->string('faculty_type', 30)->default('regular')->after('designation'); // regular, guest, visiting, contractual, adjunct
            $table->index(['faculty_type', 'partner_institute_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('faculties', function (Blueprint $table) {
            $table->dropForeign(['partner_institute_id']);
            $table->dropIndex(['faculty_type', 'partner_institute_id']);
            $table->dropColumn(['partner_institute_id', 'faculty_type']);
        });

        Schema::dropIfExists('partner_institutes');
    }
};
