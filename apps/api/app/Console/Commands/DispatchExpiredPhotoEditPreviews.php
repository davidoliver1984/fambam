<?php

namespace App\Console\Commands;

use App\Jobs\PurgeExpiredPhotoEditPreview;
use App\Tenancy\TenantOperationContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class DispatchExpiredPhotoEditPreviews extends Command
{
    protected $signature = 'fambam:dispatch-expired-photo-edit-previews';

    protected $description = 'Dispatch cleanup of unaccepted, expired Photo-edit previews';

    public function handle(): int
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->components->error('Photo-edit preview discovery requires PostgreSQL.');

            return self::FAILURE;
        }
        $due = DB::select('SELECT * FROM app_due_photo_edit_previews()');
        foreach ($due as $preview) {
            if ($preview->actor_user_id === null) {
                continue;
            }
            PurgeExpiredPhotoEditPreview::dispatch(
                TenantOperationContext::forBackground(trim((string) $preview->family_space_id),
                    (int) $preview->actor_user_id)->toArray(),
                trim((string) $preview->preview_id),
            );
        }

        $this->components->info(sprintf('Dispatched %d expired Photo-edit preview cleanup(s).', count($due)));

        return self::SUCCESS;
    }
}
