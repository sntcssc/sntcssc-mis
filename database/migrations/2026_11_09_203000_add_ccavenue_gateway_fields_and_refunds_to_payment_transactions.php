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
        Schema::table('payment_transactions', function (Blueprint $table) {
            // Gateway Fee & Charges Reconciliation
            $table->decimal('gateway_charged_amount', 10, 2)->nullable()->after('amount');
            $table->decimal('gateway_fee', 10, 2)->default(0.00)->after('gateway_charged_amount');
            $table->decimal('gateway_tax', 10, 2)->default(0.00)->after('gateway_fee');
            $table->string('gateway_bank_ref_no', 100)->nullable()->after('gateway_signature');
            $table->string('gateway_tracking_id', 100)->nullable()->index()->after('gateway_bank_ref_no');
            $table->string('gateway_payment_mode', 50)->nullable()->after('gateway_tracking_id');
            $table->string('gateway_card_name', 100)->nullable()->after('gateway_payment_mode');

            // Discrepancy & Amount Mismatch Detection
            $table->boolean('amount_mismatch')->default(false)->after('gateway_card_name');
            $table->text('amount_mismatch_notes')->nullable()->after('amount_mismatch');

            // Refund Handling
            $table->decimal('refund_amount', 10, 2)->default(0.00)->after('amount_mismatch_notes');
            $table->text('refund_reason')->nullable()->after('refund_amount');
            $table->string('refund_reference_no', 100)->nullable()->after('refund_reason');
            $table->string('refund_status', 30)->nullable()->after('refund_reference_no'); // pending, processed, failed
            $table->dateTime('refunded_at')->nullable()->after('refund_status');
            $table->foreignId('refunded_by')->nullable()->constrained('users')->nullOnDelete()->after('refunded_at');

            // Billing Details Snapshot (Mandatory for CCAvenue compliance)
            $table->string('billing_country', 50)->nullable()->after('refunded_by');
            $table->string('billing_tel', 30)->nullable()->after('billing_country');
            $table->string('billing_email', 100)->nullable()->after('billing_tel');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropForeign(['refunded_by']);
            $table->dropColumn([
                'gateway_charged_amount',
                'gateway_fee',
                'gateway_tax',
                'gateway_bank_ref_no',
                'gateway_tracking_id',
                'gateway_payment_mode',
                'gateway_card_name',
                'amount_mismatch',
                'amount_mismatch_notes',
                'refund_amount',
                'refund_reason',
                'refund_reference_no',
                'refund_status',
                'refunded_at',
                'refunded_by',
                'billing_country',
                'billing_tel',
                'billing_email',
            ]);
        });
    }
};
