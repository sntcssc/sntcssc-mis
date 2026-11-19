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
        Schema::table('student_profiles', function (Blueprint $table) {
            if (Schema::hasColumn('student_profiles', 'subcategory')) {
                $table->dropColumn('subcategory');
            }
            if (! Schema::hasColumn('student_profiles', 'distance')) {
                $table->decimal('distance', 8, 2)->nullable()->comment('Distance from home to institute in KM')->after('family_income');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            if (Schema::hasColumn('student_profiles', 'distance')) {
                $table->dropColumn('distance');
            }
            if (! Schema::hasColumn('student_profiles', 'subcategory')) {
                $table->string('subcategory', 150)->nullable()->after('subcaste');
            }
        });
    }
};
