<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Add condition to book_copies if not present
        if (Schema::hasTable('book_copies')) {
            if (! Schema::hasColumn('book_copies', 'condition')) {
                Schema::table('book_copies', function (Blueprint $table) {
                    $table->string('condition', 30)->nullable()->default('good')->index()->after('book_condition');
                });
            }

            try {
                DB::statement("UPDATE book_copies SET condition = book_condition WHERE (condition IS NULL OR condition = '') AND book_condition IS NOT NULL");
            } catch (Throwable $e) {
                // Ignore if query fails
            }
        }

        // 2. Add nationality to book_authors if not present
        if (Schema::hasTable('book_authors')) {
            if (! Schema::hasColumn('book_authors', 'nationality')) {
                Schema::table('book_authors', function (Blueprint $table) {
                    $table->string('nationality', 100)->nullable()->after('country');
                });
            }

            try {
                DB::statement("UPDATE book_authors SET nationality = country WHERE (nationality IS NULL OR nationality = '') AND country IS NOT NULL");
            } catch (Throwable $e) {
                // Ignore if query fails
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('book_copies') && Schema::hasColumn('book_copies', 'condition')) {
            Schema::table('book_copies', function (Blueprint $table) {
                $table->dropColumn('condition');
            });
        }

        if (Schema::hasTable('book_authors') && Schema::hasColumn('book_authors', 'nationality')) {
            Schema::table('book_authors', function (Blueprint $table) {
                $table->dropColumn('nationality');
            });
        }
    }
};
