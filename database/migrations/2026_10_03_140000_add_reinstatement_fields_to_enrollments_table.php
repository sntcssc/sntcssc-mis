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
        Schema::table('enrollments', function (Blueprint $table) {
            if (! Schema::hasColumn('enrollments', 'reinstated_at')) {
                $table->timestamp('reinstated_at')->nullable()->after('discontinued_by');
            }
            if (! Schema::hasColumn('enrollments', 'reinstated_by')) {
                $table->foreignId('reinstated_by')->nullable()->after('reinstated_at')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('enrollments', 'reinstatement_reason')) {
                $table->text('reinstatement_reason')->nullable()->after('reinstated_by');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            if (Schema::hasColumn('enrollments', 'reinstated_by')) {
                $table->dropForeign(['reinstated_by']);
                $table->dropColumn('reinstated_by');
            }
            if (Schema::hasColumn('enrollments', 'reinstated_at')) {
                $table->dropColumn('reinstated_at');
            }
            if (Schema::hasColumn('enrollments', 'reinstatement_reason')) {
                $table->dropColumn('reinstatement_reason');
            }
        });
    }
};
