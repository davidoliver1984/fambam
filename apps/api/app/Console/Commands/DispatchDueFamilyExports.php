<?php

namespace App\Console\Commands;

use App\Jobs\ExpireFamilyExport;
use App\Tenancy\TenantOperationContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DispatchDueFamilyExports extends Command
{
    protected $signature = 'fambam:dispatch-due-family-exports';

    protected $description = 'Dispatch idempotent cleanup for expired family archives';

    public function handle(): int
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->components->error('Family-export discovery requires PostgreSQL.');

            return self::FAILURE;
        }
        $due = DB::select('SELECT * FROM app_due_family_exports()');
        foreach ($due as $export) {
            $context = TenantOperationContext::forBackground(
                trim((string) $export->family_space_id),
                (int) $export->actor_user_id,
            );
            ExpireFamilyExport::dispatch($context->toArray(), trim((string) $export->family_export_id));
        }
        $this->components->info(sprintf('Dispatched %d expired family archive cleanup(s).', count($due)));

        return self::SUCCESS;
    }
}
