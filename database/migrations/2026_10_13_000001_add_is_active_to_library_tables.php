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
        if (Schema::hasTable('book_categories') && ! Schema::hasColumn('book_categories', 'is_active')) {
            Schema::table('book_categories', function (Blueprint $table) {
                $table->boolean('is_active')->default(true)->index()->after('status');
            });
            DB::statement("UPDATE book_categories SET is_active = (status = 'active')");
        }

        if (Schema::hasTable('book_authors') && ! Schema::hasColumn('book_authors', 'is_active')) {
            Schema::table('book_authors', function (Blueprint $table) {
                $table->boolean('is_active')->default(true)->index()->after('status');
            });
            DB::statement("UPDATE book_authors SET is_active = (status = 'active')");
        }

        if (Schema::hasTable('book_publishers') && ! Schema::hasColumn('book_publishers', 'is_active')) {
            Schema::table('book_publishers', function (Blueprint $table) {
                $table->boolean('is_active')->default(true)->index()->after('status');
            });
            DB::statement("UPDATE book_publishers SET is_active = (status = 'active')");
        }

        if (Schema::hasTable('books') && ! Schema::hasColumn('books', 'is_active')) {
            Schema::table('books', function (Blueprint $table) {
                $table->boolean('is_active')->default(true)->index()->after('status');
            });
            DB::statement("UPDATE books SET is_active = 1 WHERE status != 'inactive'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('book_categories') && Schema::hasColumn('book_categories', 'is_active')) {
            Schema::table('book_categories', function (Blueprint $table) {
                $table->dropColumn('is_active');
            });
        }

        if (Schema::hasTable('book_authors') && Schema::hasColumn('book_authors', 'is_active')) {
            Schema::table('book_authors', function (Blueprint $table) {
                $table->dropColumn('is_active');
            });
        }

        if (Schema::hasTable('book_publishers') && Schema::hasColumn('book_publishers', 'is_active')) {
            Schema::table('book_publishers', function (Blueprint $table) {
                $table->dropColumn('is_active');
            });
        }

        if (Schema::hasTable('books') && Schema::hasColumn('books', 'is_active')) {
            Schema::table('books', function (Blueprint $table) {
                $table->dropColumn('is_active');
            });
        }
    }
};
