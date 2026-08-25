<?php

namespace App\Services;

use App\Enums\AlbumVisibility;
use App\Enums\FamilySpaceRole;
use App\Enums\MembershipState;
use App\Enums\PhotoVisibility;
use App\Models\Album;
use App\Models\AlbumGrant;
use App\Models\AlbumPhoto;
use App\Models\FamilySpaceMembership;
use App\Models\MediaUpload;
use App\Models\Photo;
use App\Tenancy\TenantOperationContext;

class AlbumContributionFinalizer
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function finalize(MediaUpload $upload, TenantOperationContext $context): void
    {
        if ($upload->target_album_id === null) {
            return;
        }

        $album = Album::query()->lockForUpdate()->find($upload->target_album_id);
        $membership = FamilySpaceMembership::query()->where('family_space_id', $upload->family_space_id)
            ->where('user_id', $upload->user_id)->where('state', MembershipState::Active->value)->first();
        if ($album === null || $membership === null || ! $this->mayContribute($album, $membership)) {
            return;
        }

        $photo = Photo::query()->where('media_upload_id', $upload->id)->first();
        if ($photo === null) {
            $photo = Photo::query()->create(['family_space_id' => $upload->family_space_id,
                'media_upload_id' => $upload->id, 'created_by' => $upload->user_id,
                'visibility' => $membership->role === FamilySpaceRole::Contributor
                    ? PhotoVisibility::Private : PhotoVisibility::FamilySpace]);
            $this->audit->record('photo.created_from_album_upload', $photo, operationContext: $context);
        }
        if (! AlbumPhoto::query()->where('album_id', $album->id)->where('photo_id', $photo->id)->exists()) {
            $position = ((int) AlbumPhoto::query()->where('album_id', $album->id)->max('position')) + 1;
            $link = AlbumPhoto::query()->create(['family_space_id' => $upload->family_space_id,
                'album_id' => $album->id, 'photo_id' => $photo->id, 'position' => $position, 'added_by' => $upload->user_id]);
            $this->audit->record('album.photo_created', $link, operationContext: $context);
        }
    }

    private function mayContribute(Album $album, FamilySpaceMembership $membership): bool
    {
        if ($membership->role === FamilySpaceRole::Guest) {
            return false;
        }

        if ($membership->role->canManageMembers() || $album->created_by === $membership->user_id) {
            return true;
        }
        if ($album->visibility === AlbumVisibility::FamilySpace && $membership->role === FamilySpaceRole::Member) {
            return true;
        }

        return AlbumGrant::query()->where('album_id', $album->id)
            ->where('family_space_membership_id', $membership->id)
            ->where('can_view', true)
            ->where('can_contribute', true)
            ->exists();
    }
}
