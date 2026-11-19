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
        // 1. Books: add classification_no alias column and material_type
        Schema::table('books', function (Blueprint $table) {
            if (! Schema::hasColumn('books', 'classification_no')) {
                $table->string('classification_no', 50)->nullable()->index()->after('classification_number');
            }
            if (! Schema::hasColumn('books', 'material_type')) {
                $table->string('material_type', 30)->default('book')->index()->after('is_reference_only');
            }
        });

        // 2. Book copies: add classification_no alias column
        Schema::table('book_copies', function (Blueprint $table) {
            if (! Schema::hasColumn('book_copies', 'classification_no')) {
                $table->string('classification_no', 50)->nullable()->index()->after('classification_number');
            }
        });

        // Sync existing classification_number into classification_no
        try {
            DB::statement('UPDATE books SET classification_no = classification_number WHERE classification_no IS NULL');
            DB::statement('UPDATE book_copies SET classification_no = classification_number WHERE classification_no IS NULL');
        } catch (Throwable $e) {
            // Ignore if DB syntax differs or empty
        }

        // 3. Book issues: add in-house reading room tracking columns
        Schema::table('book_issues', function (Blueprint $table) {
            if (! Schema::hasColumn('book_issues', 'issue_type')) {
                $table->string('issue_type', 30)->default('circulation')->index()->after('status');
            }
            if (! Schema::hasColumn('book_issues', 'inhouse_session_token')) {
                $table->string('inhouse_session_token', 50)->nullable()->index()->after('issue_type');
            }
            if (! Schema::hasColumn('book_issues', 'reader_name')) {
                $table->string('reader_name', 150)->nullable()->after('inhouse_session_token');
            }
            if (! Schema::hasColumn('book_issues', 'reader_phone')) {
                $table->string('reader_phone', 50)->nullable()->after('reader_name');
            }
            if (! Schema::hasColumn('book_issues', 'reader_identifier')) {
                $table->string('reader_identifier', 50)->nullable()->after('reader_phone');
            }
            if (! Schema::hasColumn('book_issues', 'reading_room_seat_no')) {
                $table->string('reading_room_seat_no', 50)->nullable()->after('reader_identifier');
            }
            if (! Schema::hasColumn('book_issues', 'due_time')) {
                $table->string('due_time', 10)->nullable()->after('due_date');
            }
            if (! Schema::hasColumn('book_issues', 'returned_time')) {
                $table->dateTime('returned_time')->nullable()->after('return_date');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('book_issues', function (Blueprint $table) {
            $table->dropColumn([
                'issue_type',
                'inhouse_session_token',
                'reader_name',
                'reader_phone',
                'reader_identifier',
                'reading_room_seat_no',
                'due_time',
                'returned_time',
            ]);
        });

        Schema::table('book_copies', function (Blueprint $table) {
            if (Schema::hasColumn('book_copies', 'classification_no')) {
                $table->dropColumn('classification_no');
            }
        });

        Schema::table('books', function (Blueprint $table) {
            $table->dropColumn(['classification_no', 'material_type']);
        });
    }
};
