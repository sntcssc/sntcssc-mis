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
        if (Schema::hasTable('library_deposits')) {
            Schema::table('library_deposits', function (Blueprint $table) {
                if (! Schema::hasColumn('library_deposits', 'clearance_status')) {
                    $table->string('clearance_status', 30)->default('not_required')->index()->after('status');
                }
                if (! Schema::hasColumn('library_deposits', 'clearance_certificate_no')) {
                    $table->string('clearance_certificate_no', 50)->nullable()->unique()->after('clearance_status');
                }
                if (! Schema::hasColumn('library_deposits', 'cleared_at')) {
                    $table->timestamp('cleared_at')->nullable()->after('clearance_certificate_no');
                }
                if (! Schema::hasColumn('library_deposits', 'cleared_by_id')) {
                    $table->foreignId('cleared_by_id')->nullable()->after('cleared_at')->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn('library_deposits', 'clearance_remarks')) {
                    $table->text('clearance_remarks')->nullable()->after('cleared_by_id');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('library_deposits')) {
            Schema::table('library_deposits', function (Blueprint $table) {
                if (Schema::hasColumn('library_deposits', 'cleared_by_id')) {
                    $table->dropForeign(['cleared_by_id']);
                }
                $columns = array_filter([
                    'clearance_status',
                    'clearance_certificate_no',
                    'cleared_at',
                    'cleared_by_id',
                    'clearance_remarks',
                ], fn ($col) => Schema::hasColumn('library_deposits', $col));

                if (! empty($columns)) {
                    $table->dropColumn($columns);
                }
            });
        }
    }
};
