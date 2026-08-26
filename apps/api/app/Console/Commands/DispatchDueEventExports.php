<?php

namespace App\Console\Commands;

use App\Jobs\ExpireEventExport;
use App\Tenancy\TenantOperationContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DispatchDueEventExports extends Command
{
    protected $signature = 'fambam:dispatch-due-event-exports';

    protected $description = 'Dispatch idempotent cleanup for expired Event archives';

    public function handle(): int
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->components->error('Event-export discovery requires PostgreSQL.');

            return self::FAILURE;
        }

        $due = DB::select('SELECT * FROM app_due_event_exports()');
        foreach ($due as $export) {
            $context = TenantOperationContext::forBackground(
                trim((string) $export->family_space_id),
                (int) $export->actor_user_id,
            );
            ExpireEventExport::dispatch($context->toArray(), trim((string) $export->event_export_id));
        }

        $this->components->info(sprintf('Dispatched %d expired Event archive cleanup(s).', count($due)));

        return self::SUCCESS;
    }
}
