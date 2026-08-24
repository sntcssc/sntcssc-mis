<?php

namespace App\Console\Commands;

use App\Services\TicketService;
use Illuminate\Console\Command;

class CheckTicketSlaBreachesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:tickets:check-sla';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scan open support tickets for response and resolution SLA deadline breaches';

    /**
     * Execute the console command.
     */
    public function handle(TicketService $service): int
    {
        $this->info('Scanning active support tickets for SLA breaches...');

        $breaches = $service->checkSlaBreaches();

        $this->info("Scan completed. Flagged {$breaches} SLA breach event(s).");

        return self::SUCCESS;
    }
}
