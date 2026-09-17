<?php

namespace App\Services;

use App\Media\MediaObjectStorage;
use App\Models\Photo;
use App\Models\PhotoEditPreview;
use App\Models\PhotoVersion;
use App\Models\User;
use App\PhotoEditing\EditRecipe;
use App\PhotoEditing\PhotoEditRenderer;
use App\PhotoEditing\RestoreAnalyzer;
use App\PhotoEditing\RestoreProvenance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class PhotoEditorManager
{
    public function __construct(
        private readonly MediaObjectStorage $storage,
        private readonly PhotoEditRenderer $renderer,
        private readonly RestoreAnalyzer $restoreAnalyzer,
        private readonly RestoreProvenance $restoreProvenance,
        private readonly EditRecipe $recipes,
        private readonly AuditRecorder $audit,
    ) {}

    /** @param array<string, mixed>|null $submittedRecipe
     * @return array{outcome:string,preview?:PhotoEditPreview}
     */
    public function preview(Photo $photo, User $actor, ?array $submittedRecipe, Request $request): array
    {
        $upload = $photo->mediaUpload;
        if ($upload->canonical_object_key === null) {
            throw new ConflictHttpException('This Photo has no canonical asset to edit.');
        }
        $recipe = $submittedRecipe === null
            ? $this->recipes->validate($photo->active_photo_version_id === null
                ? $this->recipes->identity() : $photo->activeVersion->edit_recipe)
            : $this->recipes->validate($submittedRecipe);
        $canonicalPath = $this->temporaryPath();
        try {
            $this->storage->downloadTo($upload->canonical_object_key, $canonicalPath);
            $restore = $submittedRecipe === null ? $this->restoreAnalyzer->analyze($canonicalPath) : null;
            if ($submittedRecipe === null && $restore === null) {
                return ['outcome' => 'no_improvement_found'];
            }
            if ($restore !== null) {
                $restore = $this->restoreProvenance->validate($restore);
            }
            $output = $this->renderer->render($canonicalPath, $recipe, $restore);
            try {
                $id = (string) Str::ulid();
                $key = "families/{$photo->family_space_id}/photos/{$photo->id}/edit-previews/{$id}.webp";
                $this->storage->finalizeWriteOnce($output->path, $key, $output->sha256);
                try {
                    $preview = PhotoEditPreview::query()->create([
                        'id' => $id, 'family_space_id' => $photo->family_space_id, 'photo_id' => $photo->id,
                        'requested_by' => $actor->id, 'base_photo_version_id' => $photo->active_photo_version_id,
                        'edit_recipe' => $recipe, 'restore' => $restore, 'object_key' => $key,
                        'expires_at' => now()->addMinutes(30),
                    ]);
                } catch (\Throwable $exception) {
                    $this->storage->delete($key);
                    throw $exception;
                }
            } finally {
                @unlink($output->path);
            }
        } finally {
            @unlink($canonicalPath);
        }

        return ['outcome' => 'preview_ready', 'preview' => $preview];
    }

    public function apply(Photo $photo, string $previewId, User $actor, Request $request): PhotoVersion
    {
        $existing = PhotoVersion::query()->where('photo_id', $photo->id)->find($previewId);
        if ($existing !== null) {
            return $existing;
        }
        try {
            $preview = $this->findPreview($photo, $previewId, $actor);
        } catch (NotFoundHttpException $exception) {
            $completed = PhotoVersion::query()->where('photo_id', $photo->id)->find($previewId);
            if ($completed !== null) {
                return $completed;
            }
            throw $exception;
        }
        $path = $this->temporaryPath();
        try {
            $this->storage->downloadTo($preview->object_key, $path);
            $sha = hash_file('sha256', $path);
            if ($sha === false) {
                throw new ConflictHttpException('The preview cannot be verified.');
            }
            $finalKey = "families/{$photo->family_space_id}/photos/{$photo->id}/versions/{$preview->id}.webp";
            $finalized = false;
            try {
                $version = DB::transaction(function () use ($photo, $preview, $actor, $request, $path, $sha, $finalKey, &$finalized): PhotoVersion {
                    $locked = Photo::query()->whereKey($photo->id)->lockForUpdate()->firstOrFail();
                    $version = PhotoVersion::query()->where('photo_id', $photo->id)->find($preview->id);
                    if ($version !== null) {
                        return $version;
                    }
                    $current = PhotoEditPreview::query()->whereKey($preview->id)->lockForUpdate()->first();
                    if ($current === null || $current->expires_at->isPast()
                        || $current->base_photo_version_id !== $locked->active_photo_version_id) {
                        throw new ConflictHttpException('The edit preview is no longer current.');
                    }
                    $this->storage->finalizeWriteOnce($path, $finalKey, $sha);
                    $finalized = true;
                    $version = PhotoVersion::query()->create([
                        'id' => $preview->id, 'family_space_id' => $photo->family_space_id,
                        'photo_id' => $photo->id, 'edit_recipe' => $current->edit_recipe,
                        'restore' => $current->restore, 'derived_object_key' => $finalKey,
                        'created_by' => $actor->id,
                    ]);
                    $locked->update(['active_photo_version_id' => $version->id]);
                    $current->update(['expires_at' => now()]);
                    $this->audit->record($version->restore === null ? 'photo.version_activated' : 'photo.restore_applied',
                        $locked, $actor, $request, ['photo_version_id' => $version->id]);

                    return $version;
                });
            } catch (\Throwable $exception) {
                if ($finalized) {
                    $this->storage->delete($finalKey);
                }
                throw $exception;
            }
            $this->storage->delete($preview->object_key);
            PhotoEditPreview::query()->whereKey($preview->id)->delete();

            return $version;
        } finally {
            @unlink($path);
        }
    }

    public function discard(Photo $photo, string $previewId, User $actor): void
    {
        $preview = $this->findPreview($photo, $previewId, $actor);
        $this->storage->delete($preview->object_key);
        $preview->delete();
    }

    public function activate(Photo $photo, ?string $versionId, User $actor, Request $request): ?PhotoVersion
    {
        return DB::transaction(function () use ($photo, $versionId, $actor, $request): ?PhotoVersion {
            $locked = Photo::query()->whereKey($photo->id)->lockForUpdate()->firstOrFail();
            $version = $versionId === null ? null
                : PhotoVersion::query()->where('photo_id', $photo->id)->findOrFail($versionId);
            $locked->update(['active_photo_version_id' => $version?->id]);
            $this->audit->record('photo.version_activated', $locked, $actor, $request,
                ['photo_version_id' => $version?->id]);

            return $version;
        });
    }

    private function findPreview(Photo $photo, string $id, User $actor): PhotoEditPreview
    {
        $preview = PhotoEditPreview::query()->where('photo_id', $photo->id)->findOrFail($id);
        if ($preview->requested_by !== $actor->id || $preview->expires_at->isPast()) {
            throw new NotFoundHttpException;
        }

        return $preview;
    }

    private function temporaryPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fambam-photo-edit-');
        if ($path === false) {
            throw new \RuntimeException('A temporary Photo-edit file could not be created.');
        }
        chmod($path, 0600);

        return $path;
    }
}
