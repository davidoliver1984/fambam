<?php

namespace App\Services;

use App\Enums\MediaUploadState;
use App\Models\FamilySpace;
use App\Models\MediaUpload;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class MediaUploadBatchManager
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /** @return array<string, mixed> */
    public function status(FamilySpace $familySpace, User $actor, string $batchId): array
    {
        $query = MediaUpload::query()
            ->where('family_space_id', $familySpace->id)
            ->where('upload_batch_id', $batchId);
        if (! $this->tenantContext->membership()->role->canManageMembers()) {
            $query->where('user_id', $actor->id);
        }
        $uploads = $query
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
        if ($uploads->isEmpty()) {
            throw (new ModelNotFoundException)->setModel(MediaUpload::class);
        }

        $counts = [];
        foreach (MediaUploadState::cases() as $state) {
            $counts[$state->value] = 0;
        }
        foreach ($uploads as $upload) {
            $counts[$upload->state->value]++;
        }
        $activeStates = [
            MediaUploadState::Initiated,
            MediaUploadState::Uploaded,
            MediaUploadState::Verifying,
            MediaUploadState::Preserved,
            MediaUploadState::Processing,
            MediaUploadState::Degraded,
        ];
        $includeRejectionReason = $this->tenantContext->membership()->role->canManageMembers();

        return [
            'batch_id' => $batchId,
            'total' => $uploads->count(),
            'active' => $uploads->contains(
                static fn (MediaUpload $upload): bool => in_array($upload->state, $activeStates, true),
            ),
            'counts' => $counts,
            'items' => $uploads->map(static function (MediaUpload $upload) use ($includeRejectionReason): array {
                $item = [
                    'id' => $upload->id,
                    'state' => $upload->state->value,
                    'client_filename' => $upload->client_filename,
                    'byte_size' => $upload->byte_size,
                    'uploaded_at' => $upload->uploaded_at?->toAtomString(),
                ];
                if ($includeRejectionReason) {
                    $item['rejection_reason'] = $upload->rejection_reason;
                }

                return $item;
            })->all(),
        ];
    }
}
