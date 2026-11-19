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
        if (Schema::hasTable('book_categories')) {
            if (! Schema::hasColumn('book_categories', 'ddc_classification')) {
                Schema::table('book_categories', function (Blueprint $table) {
                    $table->string('ddc_classification', 50)->nullable()->after('code');
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('book_categories')) {
            if (Schema::hasColumn('book_categories', 'ddc_classification')) {
                Schema::table('book_categories', function (Blueprint $table) {
                    $table->dropColumn('ddc_classification');
                });
            }
        }
    }
};
