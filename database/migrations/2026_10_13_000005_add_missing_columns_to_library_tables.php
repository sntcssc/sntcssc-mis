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
        // 1. book_copies: add price column and sync from purchase_cost
        if (Schema::hasTable('book_copies')) {
            if (! Schema::hasColumn('book_copies', 'price')) {
                Schema::table('book_copies', function (Blueprint $table) {
                    $table->decimal('price', 10, 2)->default(0.00)->after('purchase_cost');
                });
            }
            DB::statement('UPDATE book_copies SET price = purchase_cost WHERE price = 0.00 AND purchase_cost > 0.00');
        }

        // 2. book_fines: add amount, fine_number, fine_type, etc.
        if (Schema::hasTable('book_fines')) {
            Schema::table('book_fines', function (Blueprint $table) {
                if (! Schema::hasColumn('book_fines', 'fine_number')) {
                    $table->string('fine_number', 50)->nullable()->index()->after('id');
                }
                if (! Schema::hasColumn('book_fines', 'fine_type')) {
                    $table->string('fine_type', 30)->default('overdue')->index()->after('fine_no');
                }
                if (! Schema::hasColumn('book_fines', 'amount')) {
                    $table->decimal('amount', 10, 2)->default(0.00)->after('calculated_fine');
                }
                if (! Schema::hasColumn('book_fines', 'payment_reference')) {
                    $table->string('payment_reference', 100)->nullable()->after('payment_method');
                }
                if (! Schema::hasColumn('book_fines', 'collected_by_id')) {
                    $table->foreignId('collected_by_id')->nullable()->after('payment_reference')->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn('book_fines', 'waived_by_id')) {
                    $table->foreignId('waived_by_id')->nullable()->after('waived_by')->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn('book_fines', 'created_by_id')) {
                    $table->foreignId('created_by_id')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
                }
            });

            // Make book_issue_id nullable if possible
            try {
                DB::statement('ALTER TABLE book_fines MODIFY book_issue_id BIGINT UNSIGNED NULL');
            } catch (Throwable $e) {
                // Ignore if not supported by driver
            }

            // Sync data
            DB::statement('UPDATE book_fines SET fine_number = fine_no WHERE fine_number IS NULL OR fine_number = ""');
            DB::statement('UPDATE book_fines SET amount = calculated_fine WHERE amount = 0.00 AND calculated_fine > 0.00');
            DB::statement('UPDATE book_fines SET waived_by_id = waived_by WHERE waived_by_id IS NULL AND waived_by IS NOT NULL');
            DB::statement('UPDATE book_fines SET created_by_id = created_by WHERE created_by_id IS NULL AND created_by IS NOT NULL');
        }

        // 3. book_reservations: add reservation_number
        if (Schema::hasTable('book_reservations')) {
            if (! Schema::hasColumn('book_reservations', 'reservation_number')) {
                Schema::table('book_reservations', function (Blueprint $table) {
                    $table->string('reservation_number', 50)->nullable()->index()->after('id');
                });
            }
            DB::statement('UPDATE book_reservations SET reservation_number = reservation_no WHERE reservation_number IS NULL OR reservation_number = ""');
        }

        // 4. book_issues: add issue_number
        if (Schema::hasTable('book_issues')) {
            if (! Schema::hasColumn('book_issues', 'issue_number')) {
                Schema::table('book_issues', function (Blueprint $table) {
                    $table->string('issue_number', 50)->nullable()->index()->after('id');
                });
            }
            DB::statement('UPDATE book_issues SET issue_number = issue_no WHERE issue_number IS NULL OR issue_number = ""');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('book_copies') && Schema::hasColumn('book_copies', 'price')) {
            Schema::table('book_copies', function (Blueprint $table) {
                $table->dropColumn('price');
            });
        }

        if (Schema::hasTable('book_fines')) {
            Schema::table('book_fines', function (Blueprint $table) {
                $cols = array_filter([
                    Schema::hasColumn('book_fines', 'fine_number') ? 'fine_number' : null,
                    Schema::hasColumn('book_fines', 'fine_type') ? 'fine_type' : null,
                    Schema::hasColumn('book_fines', 'amount') ? 'amount' : null,
                    Schema::hasColumn('book_fines', 'payment_reference') ? 'payment_reference' : null,
                    Schema::hasColumn('book_fines', 'collected_by_id') ? 'collected_by_id' : null,
                    Schema::hasColumn('book_fines', 'waived_by_id') ? 'waived_by_id' : null,
                    Schema::hasColumn('book_fines', 'created_by_id') ? 'created_by_id' : null,
                ]);
                if (! empty($cols)) {
                    $table->dropColumn($cols);
                }
            });
        }

        if (Schema::hasTable('book_reservations') && Schema::hasColumn('book_reservations', 'reservation_number')) {
            Schema::table('book_reservations', function (Blueprint $table) {
                $table->dropColumn('reservation_number');
            });
        }

        if (Schema::hasTable('book_issues') && Schema::hasColumn('book_issues', 'issue_number')) {
            Schema::table('book_issues', function (Blueprint $table) {
                $table->dropColumn('issue_number');
            });
        }
    }
};
