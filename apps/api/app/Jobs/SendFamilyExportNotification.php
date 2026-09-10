<?php

namespace App\Jobs;

use App\Enums\FamilyExportState;
use App\Services\FamilyExportManager;
use App\Tenancy\TenantOperationContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendFamilyExportNotification implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    /** @param array{family_space_id:string,actor_user_id:int,correlation_id:string,traceparent:string} $context */
    public function __construct(
        public array $context,
        public string $familyExportId,
        public FamilyExportState $terminalState,
    ) {}

    public function uniqueId(): string
    {
        return "family-export-notification:{$this->familyExportId}:{$this->terminalState->value}";
    }

    public function handle(FamilyExportManager $exports): void
    {
        $exports->deliverTerminalNotification(
            TenantOperationContext::fromArray($this->context),
            $this->familyExportId,
            $this->terminalState,
        );
    }
}
