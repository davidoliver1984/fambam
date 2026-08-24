<?php

namespace App\Services;

use App\Models\Photo;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PhotoDeletionManager
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function delete(Photo $photo, User $actor, Request $request): void
    {
        DB::transaction(function () use ($photo, $actor, $request): void {
            $locked = Photo::query()->lockForUpdate()->findOrFail($photo->id);
            $locked->update(['deleted_by' => $actor->id]);
            $locked->delete();
            $this->audit->record('photo.deleted', $locked, $actor, $request);
        });
    }

    public function restore(Photo $photo, User $actor, Request $request): Photo
    {
        return DB::transaction(function () use ($photo, $actor, $request): Photo {
            $locked = Photo::onlyTrashed()->lockForUpdate()->findOrFail($photo->id);
            $locked->restore();
            $locked->update(['deleted_by' => null]);
            $this->audit->record('photo.restored', $locked, $actor, $request);

            return $locked->load('mediaUpload');
        });
    }
}
