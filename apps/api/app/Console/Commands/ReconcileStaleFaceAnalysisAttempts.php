<?php

namespace App\Console\Commands;

use App\Services\FaceAnalysisPipeline;
use App\Tenancy\TenantOperationContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReconcileStaleFaceAnalysisAttempts extends Command
{
    protected $signature = 'fambam:reconcile-stale-face-analysis-attempts';

    protected $description = 'Fail and retry face-analysis attempts past their calibrated staleness window';

    public function handle(FaceAnalysisPipeline $pipeline): int
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->components->error('Face-analysis reconciliation requires PostgreSQL.');

            return self::FAILURE;
        }
        $cutoff = now()->subMinutes((int) config('image-analysis.attempt_stale_minutes'));
        $due = DB::select('SELECT * FROM app_due_face_analysis_attempts(?)', [$cutoff]);
        foreach ($due as $attempt) {
            if (! is_numeric($attempt->actor_user_id ?? null)) {
                continue;
            }
            $pipeline->timeout(
                TenantOperationContext::forBackground(
                    trim((string) $attempt->family_space_id),
                    (int) $attempt->actor_user_id,
                ),
                trim((string) $attempt->attempt_id),
            );
        }
        $this->components->info(sprintf('Reconciled %d stale face-analysis attempt(s).', count($due)));

        return self::SUCCESS;
    }
}
