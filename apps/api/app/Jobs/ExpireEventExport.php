<?php

namespace App\Jobs;

use App\Services\EventExportManager;
use App\Tenancy\TenantOperationContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ExpireEventExport implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    /** @param array{family_space_id: string, actor_user_id: int, correlation_id: string, traceparent: string} $context */
    public function __construct(public array $context, public string $eventExportId) {}

    public function uniqueId(): string
    {
        return "event-export-expiry:{$this->eventExportId}";
    }

    public function handle(EventExportManager $exports): void
    {
        $exports->expire(TenantOperationContext::fromArray($this->context), $this->eventExportId);
    }
}
