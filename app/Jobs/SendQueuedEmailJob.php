<?php

namespace App\Jobs;

use App\Models\CommunicationLog;
use App\Models\User;
use App\Services\CommunicationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendQueuedEmailJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $variables
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $email,
        public string $subject,
        public string $htmlBody,
        public array $options = [],
        public ?User $user = null,
        public ?string $templateCode = null,
        public array $variables = [],
        public string $type = CommunicationLog::TYPE_CUSTOM_INDIVIDUAL,
        public ?int $sentBy = null,
        public ?int $campaignId = null,
        public array $metadata = []
    ) {}

    /**
     * Execute the job.
     */
    public function handle(CommunicationService $communicationService): void
    {
        $communicationService->logAndSendEmail(
            email: $this->email,
            subject: $this->subject,
            htmlBody: $this->htmlBody,
            options: $this->options,
            user: $this->user,
            templateCode: $this->templateCode,
            variables: $this->variables,
            type: $this->type,
            sentBy: $this->sentBy,
            campaignId: $this->campaignId,
            metadata: $this->metadata
        );
    }
}
