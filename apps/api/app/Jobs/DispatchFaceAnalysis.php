<?php

namespace App\Jobs;

use App\Services\FaceAnalysisPipeline;
use App\Tenancy\TenantOperationContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DispatchFaceAnalysis implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    /** @param array{family_space_id: string, actor_user_id: int, correlation_id: string, traceparent: string} $context */
    public function __construct(
        public array $context,
        public string $mediaUploadId,
        public string $canonicalSha256,
    ) {}

    public function uniqueId(): string
    {
        $identity = config('image-analysis.identity');

        return implode(':', [
            'face-analysis', $this->mediaUploadId, $this->canonicalSha256,
            $identity['provider'], $identity['model_identifier'],
            $identity['model_weight_checksum'], $identity['config_hash'],
        ]);
    }

    public function handle(FaceAnalysisPipeline $pipeline): void
    {
        $pipeline->dispatch(
            TenantOperationContext::fromArray($this->context),
            $this->mediaUploadId,
            $this->canonicalSha256,
        );
    }
}
