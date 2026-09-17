<?php

namespace App\Jobs;

use App\Services\ExpiredPhotoEditPreviewCleaner;
use App\Tenancy\TenantOperationContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class PurgeExpiredPhotoEditPreview implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    /** @param array{family_space_id:string,actor_user_id:int,correlation_id:string,traceparent:string} $context */
    public function __construct(public array $context, public string $previewId) {}

    public function uniqueId(): string
    {
        return "photo-edit-preview:{$this->previewId}";
    }

    public function handle(ExpiredPhotoEditPreviewCleaner $cleaner): void
    {
        $cleaner->purge(TenantOperationContext::fromArray($this->context), $this->previewId);
    }
}
