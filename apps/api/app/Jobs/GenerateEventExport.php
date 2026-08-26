<?php

namespace App\Jobs;

use App\Services\EventExportManager;
use App\Tenancy\TenantOperationContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class GenerateEventExport implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    public int $timeout = 900;

    public bool $failOnTimeout = true;

    /** @param array{family_space_id: string, actor_user_id: int, correlation_id: string, traceparent: string} $context */
    public function __construct(public array $context, public string $eventExportId) {}

    public function uniqueId(): string
    {
        return "event-export:{$this->eventExportId}";
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("event-export:{$this->eventExportId}"))->expireAfter(960)];
    }

    public function handle(EventExportManager $exports): void
    {
        $exports->generate(TenantOperationContext::fromArray($this->context), $this->eventExportId);
    }

    public function failed(\Throwable $exception): void
    {
        app(EventExportManager::class)->markFailed(
            TenantOperationContext::fromArray($this->context),
            $this->eventExportId,
        );
    }
}
