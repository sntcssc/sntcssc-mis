<?php

namespace App\Jobs;

use App\Models\CommunicationCampaign;
use App\Services\CommunicationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DispatchCommunicationCampaignJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public CommunicationCampaign $campaign,
        public ?int $adminId = null
    ) {}

    /**
     * Execute the job.
     */
    public function handle(CommunicationService $communicationService): void
    {
        $communicationService->dispatchCampaign($this->campaign, $this->adminId);
    }
}
