<?php

namespace App\Console\Commands;

use App\Jobs\GeneratePerceptualSimilarity;
use App\Tenancy\TenantOperationContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DispatchMissingPerceptualHashes extends Command
{
    protected $signature = 'fambam:dispatch-missing-perceptual-hashes';

    protected $description = 'Dispatch versioned perceptual hashing for eligible Photos without the current hash';

    public function handle(): int
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->components->error('Perceptual-hash discovery requires PostgreSQL.');

            return self::FAILURE;
        }

        $algorithm = (string) config('media.processing.perceptual_algorithm');
        $processingVersion = (int) config('media.processing.perceptual_processing_version');
        $due = DB::select('SELECT * FROM app_due_perceptual_hashes(?, ?)', [$algorithm, $processingVersion]);

        foreach ($due as $upload) {
            $context = TenantOperationContext::forBackground(
                trim((string) $upload->family_space_id),
                (int) $upload->actor_user_id,
            );
            GeneratePerceptualSimilarity::dispatch(
                $context->toArray(),
                trim((string) $upload->media_upload_id),
                trim((string) $upload->canonical_sha256),
                $algorithm,
                $processingVersion,
            );
        }

        $this->components->info(sprintf('Dispatched %d perceptual hash job(s).', count($due)));

        return self::SUCCESS;
    }
}
