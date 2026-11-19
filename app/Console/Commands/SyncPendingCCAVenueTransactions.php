<?php

namespace App\Console\Commands;

use App\Models\PaymentTransaction;
use App\Services\Payment\FeePaymentService;
use App\Services\Payment\Gateways\CCAVenueGateway;
use Illuminate\Console\Command;

class SyncPendingCCAVenueTransactions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payments:sync-ccavenue-pending {--hours=24 : Scan pending transactions created within the last N hours}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Query CCAvenue Order Status Tracker API to reconcile interrupted, dropped, or unconfirmed pending transactions';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $gateway = new CCAVenueGateway;

        if (! $gateway->isEnabled() || ! $gateway->isConfigured()) {
            $this->warn('CCAVenue gateway is either disabled or not configured with API credentials.');

            return self::SUCCESS;
        }

        $hours = (int) $this->option('hours') ?: 24;
        $cutoffRecent = now()->subMinutes(10);
        $cutoffOld = now()->subHours($hours);

        $pending = PaymentTransaction::query()
            ->where('gateway', PaymentTransaction::GATEWAY_CCAVENUE)
            ->where('status', PaymentTransaction::STATUS_PENDING)
            ->where('created_at', '<=', $cutoffRecent)
            ->where('created_at', '>=', $cutoffOld)
            ->orderBy('id')
            ->get();

        if ($pending->isEmpty()) {
            $this->info('No pending CCAvenue transactions requiring reconciliation.');

            return self::SUCCESS;
        }

        $this->info("Found {$pending->count()} pending CCAvenue transaction(s) to verify...");

        $successCount = 0;
        $failedCount = 0;
        $awaitedCount = 0;

        foreach ($pending as $transaction) {
            $this->line("Reconciling Transaction #{$transaction->transaction_no} (Order: {$transaction->gateway_order_id})...");

            $res = FeePaymentService::syncWithCCAvenue($transaction);

            if ($res['success']) {
                $status = $res['order_status'] ?? 'Unknown';
                if ($status === 'Successful') {
                    $successCount++;
                    $this->info(" -> SUCCESS: {$res['message']}");
                } elseif (in_array($status, ['Aborted', 'Failure', 'Unsuccessful'], true)) {
                    $failedCount++;
                    $this->warn(" -> FAILED: {$res['message']}");
                } else {
                    $awaitedCount++;
                    $this->line(" -> AWAITED: {$res['message']}");
                }
            } else {
                $this->error(" -> ERROR: {$res['message']}");
            }
        }

        $this->newLine();
        $this->info("Reconciliation summary: {$successCount} confirmed, {$failedCount} failed, {$awaitedCount} awaited.");

        return self::SUCCESS;
    }
}
