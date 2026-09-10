<?php

namespace App\Jobs;

use App\Services\FamilyExportManager;
use App\Tenancy\TenantOperationContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class GenerateFamilyExport implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    public int $timeout = 900;

    public bool $failOnTimeout = true;

    /** @param array{family_space_id:string,actor_user_id:int,correlation_id:string,traceparent:string} $context */
    public function __construct(public array $context, public string $familyExportId) {}

    public function uniqueId(): string
    {
        return "family-export:{$this->familyExportId}";
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("family-export:{$this->familyExportId}"))->expireAfter(960)];
    }

    public function handle(FamilyExportManager $exports): void
    {
        $exports->generate(TenantOperationContext::fromArray($this->context), $this->familyExportId);
    }

    public function failed(\Throwable $exception): void
    {
        app(FamilyExportManager::class)->markFailed(
            TenantOperationContext::fromArray($this->context),
            $this->familyExportId,
        );
    }
}
