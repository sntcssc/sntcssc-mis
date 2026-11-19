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
        Schema::table('book_issues', function (Blueprint $table) {
            if (! Schema::hasColumn('book_issues', 'renewal_status')) {
                $table->string('renewal_status', 30)->default('none')->index()->after('last_renewed_at');
            }
            if (! Schema::hasColumn('book_issues', 'renewal_requested_at')) {
                $table->dateTime('renewal_requested_at')->nullable()->after('renewal_status');
            }
            if (! Schema::hasColumn('book_issues', 'renewal_requested_days')) {
                $table->unsignedInteger('renewal_requested_days')->default(15)->after('renewal_requested_at');
            }
            if (! Schema::hasColumn('book_issues', 'renewal_request_reason')) {
                $table->text('renewal_request_reason')->nullable()->after('renewal_requested_days');
            }
            if (! Schema::hasColumn('book_issues', 'renewal_reviewed_by')) {
                $table->foreignId('renewal_reviewed_by')->nullable()->after('renewal_request_reason')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('book_issues', 'renewal_reviewed_at')) {
                $table->dateTime('renewal_reviewed_at')->nullable()->after('renewal_reviewed_by');
            }
            if (! Schema::hasColumn('book_issues', 'renewal_rejection_reason')) {
                $table->text('renewal_rejection_reason')->nullable()->after('renewal_reviewed_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('book_issues', function (Blueprint $table) {
            if (Schema::hasColumn('book_issues', 'renewal_reviewed_by')) {
                $table->dropForeign(['renewal_reviewed_by']);
                $table->dropColumn('renewal_reviewed_by');
            }
            $columnsToDrop = [
                'renewal_status',
                'renewal_requested_at',
                'renewal_requested_days',
                'renewal_request_reason',
                'renewal_reviewed_at',
                'renewal_rejection_reason',
            ];
            foreach ($columnsToDrop as $col) {
                if (Schema::hasColumn('book_issues', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
