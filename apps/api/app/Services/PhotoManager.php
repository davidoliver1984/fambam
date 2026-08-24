<?php

namespace App\Services;

use App\Enums\FamilySpaceRole;
use App\Enums\MediaUploadState;
use App\Enums\PersonProposalStatus;
use App\Enums\PhotoMetadataField;
use App\Enums\PhotoProvenanceRole;
use App\Enums\PhotoVisibility;
use App\Models\FamilySpace;
use App\Models\MediaUpload;
use App\Models\Person;
use App\Models\Photo;
use App\Models\PhotoMetadataProposal;
use App\Models\PhotoPerson;
use App\Models\PhotoProvenanceProposal;
use App\Models\Tag;
use App\Models\User;
use App\People\UncertainDate;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PhotoManager
{
    private const CONTENT_FIELDS = ['caption', 'description', 'archive_source_description', 'visibility'];

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $tenantContext,
    ) {}

    /** @param array<string, mixed> $input */
    public function create(FamilySpace $familySpace, User $actor, array $input, Request $request): Photo
    {
        return DB::transaction(function () use ($familySpace, $actor, $input, $request): Photo {
            $upload = MediaUpload::query()->lockForUpdate()
                ->where('family_space_id', $familySpace->id)
                ->findOrFail((string) $input['media_upload_id']);
            $role = $this->tenantContext->membership()->role;

            if ($upload->state !== MediaUploadState::Ready) {
                throw ValidationException::withMessages(['media_upload_id' => ['Only a ready upload can become a Photo.']]);
            }

            if (! $role->canManageMembers() && ($role !== FamilySpaceRole::Member || $upload->user_id !== $actor->id)) {
                throw ValidationException::withMessages([
                    'media_upload_id' => ['You may create a Photo only from your own ready upload.'],
                ]);
            }

            if (Photo::query()->where('media_upload_id', $upload->id)->exists()) {
                throw ValidationException::withMessages(['media_upload_id' => ['This upload already belongs to a Photo.']]);
            }

            $photo = Photo::query()->create([
                'family_space_id' => $familySpace->id,
                'media_upload_id' => $upload->id,
                'created_by' => $actor->id,
                'visibility' => PhotoVisibility::tryFrom((string) ($input['visibility'] ?? ''))
                    ?? PhotoVisibility::FamilySpace,
                ...$this->contentAttributes($input),
            ]);

            $this->syncTags($photo, $actor, $input['tags'] ?? [], false);
            $this->audit->record('photo.created', $photo, $actor, $request, [
                'media_upload_id' => $upload->id,
                'visibility' => $photo->visibility->value,
            ]);

            return $photo->load(['mediaUpload.uploader:id,name', 'tags:id,label']);
        });
    }

    /** @param array<string, mixed> $input */
    public function update(Photo $photo, User $actor, array $input, Request $request): Photo
    {
        return DB::transaction(function () use ($photo, $actor, $input, $request): Photo {
            $locked = Photo::query()->lockForUpdate()->findOrFail($photo->id);
            $beforeVisibility = $locked->visibility;
            $locked->update($this->contentAttributes($input));

            $this->audit->record('photo.content_updated', $locked, $actor, $request, [
                'changed_fields' => array_values(array_intersect(array_keys($locked->getChanges()), self::CONTENT_FIELDS)),
            ]);

            if ($beforeVisibility !== $locked->visibility) {
                $this->audit->record('photo.visibility_changed', $locked, $actor, $request, [
                    'from' => $beforeVisibility->value,
                    'to' => $locked->visibility->value,
                ]);
            }

            return $locked->load(['mediaUpload.uploader:id,name', 'tags:id,label']);
        });
    }

    /** @param array<string, mixed> $input */
    public function submitProvenance(Photo $photo, User $actor, array $input, Request $request): PhotoProvenanceProposal
    {
        return DB::transaction(function () use ($photo, $actor, $input, $request): PhotoProvenanceProposal {
            $locked = Photo::query()->lockForUpdate()->findOrFail($photo->id);
            $role = PhotoProvenanceRole::from((string) $input['role']);
            $personId = $input['person_id'] ?? null;
            $description = trim((string) ($input['description'] ?? '')) ?: null;
            $clears = (bool) ($input['clears_claim'] ?? false);

            if (is_string($personId)) {
                $exists = Person::query()
                    ->where('family_space_id', $locked->family_space_id)
                    ->whereKey($personId)
                    ->exists();
                if (! $exists) {
                    throw ValidationException::withMessages(['person_id' => ['The selected Person is unavailable.']]);
                }
            }

            $authoritative = $this->tenantContext->membership()->role->canManageMembers();
            $proposal = PhotoProvenanceProposal::query()->create([
                'family_space_id' => $locked->family_space_id,
                'photo_id' => $locked->id,
                'role' => $role,
                'person_id' => $personId,
                'description' => $description,
                'clears_claim' => $clears,
                'status' => $authoritative ? PersonProposalStatus::Approved : PersonProposalStatus::Pending,
                'proposed_by' => $actor->id,
                'resolved_by' => $authoritative ? $actor->id : null,
                'resolved_at' => $authoritative ? now() : null,
            ]);

            if ($authoritative) {
                $this->applyProvenance($locked, $proposal);
            }

            $this->audit->record(
                $authoritative ? 'photo.provenance_confirmed' : 'photo.provenance_proposed',
                $proposal,
                $actor,
                $request,
                ['photo_id' => $locked->id, 'role' => $role->value],
            );

            return $proposal->load('person:id,preferred_name');
        });
    }

    public function resolveProvenance(
        Photo $photo,
        PhotoProvenanceProposal $proposal,
        User $actor,
        PersonProposalStatus $resolution,
        Request $request,
    ): PhotoProvenanceProposal {
        return DB::transaction(function () use ($photo, $proposal, $actor, $resolution, $request): PhotoProvenanceProposal {
            $lockedPhoto = Photo::query()->lockForUpdate()->findOrFail($photo->id);
            $lockedProposal = PhotoProvenanceProposal::query()->lockForUpdate()->findOrFail($proposal->id);

            if ($lockedProposal->photo_id !== $lockedPhoto->id
                || $lockedProposal->status !== PersonProposalStatus::Pending) {
                throw ValidationException::withMessages(['proposal' => ['This proposal can no longer be resolved.']]);
            }

            if ($resolution === PersonProposalStatus::Approved) {
                $this->applyProvenance($lockedPhoto, $lockedProposal);
            }

            $lockedProposal->update([
                'status' => $resolution,
                'resolved_by' => $actor->id,
                'resolved_at' => now(),
            ]);
            $this->audit->record(
                $resolution === PersonProposalStatus::Approved
                    ? 'photo.provenance_confirmed'
                    : 'photo.provenance_rejected',
                $lockedProposal,
                $actor,
                $request,
                ['photo_id' => $lockedPhoto->id, 'role' => $lockedProposal->role->value],
            );

            return $lockedProposal->load('person:id,preferred_name');
        });
    }

    /** @param array<string, mixed> $input */
    public function submitMetadata(Photo $photo, User $actor, array $input, Request $request): PhotoMetadataProposal
    {
        return DB::transaction(function () use ($photo, $actor, $input, $request): PhotoMetadataProposal {
            $locked = Photo::query()->lockForUpdate()->findOrFail($photo->id);
            $field = PhotoMetadataField::from((string) $input['field']);
            $clears = (bool) ($input['clears_claim'] ?? false);
            $date = null;
            if (! $clears && $field === PhotoMetadataField::HistoricalDate) {
                /** @var array{precision: string, value?: string|null} $dateInput */
                $dateInput = $input['date'];
                $date = UncertainDate::fromInput($dateInput);
            }
            $authoritative = $this->tenantContext->membership()->role->canManageMembers();

            $proposal = PhotoMetadataProposal::query()->create([
                'family_space_id' => $locked->family_space_id,
                'photo_id' => $locked->id,
                'field' => $field,
                'date_precision' => $date?->precision,
                'date_value' => $date?->storageDate(),
                'location_description' => $clears ? null : (trim((string) ($input['location_description'] ?? '')) ?: null),
                'clears_claim' => $clears,
                'status' => $authoritative ? PersonProposalStatus::Approved : PersonProposalStatus::Pending,
                'proposed_by' => $actor->id,
                'resolved_by' => $authoritative ? $actor->id : null,
                'resolved_at' => $authoritative ? now() : null,
            ]);

            if ($authoritative) {
                $this->applyMetadata($locked, $proposal);
            }

            $this->audit->record(
                $authoritative ? 'photo.metadata_confirmed' : 'photo.metadata_proposed',
                $proposal,
                $actor,
                $request,
                ['photo_id' => $locked->id, 'field' => $field->value],
            );

            return $proposal;
        });
    }

    public function resolveMetadata(
        Photo $photo,
        PhotoMetadataProposal $proposal,
        User $actor,
        PersonProposalStatus $resolution,
        Request $request,
    ): PhotoMetadataProposal {
        return DB::transaction(function () use ($photo, $proposal, $actor, $resolution, $request): PhotoMetadataProposal {
            $lockedPhoto = Photo::query()->lockForUpdate()->findOrFail($photo->id);
            $lockedProposal = PhotoMetadataProposal::query()->lockForUpdate()->findOrFail($proposal->id);
            if ($lockedProposal->photo_id !== $lockedPhoto->id
                || $lockedProposal->status !== PersonProposalStatus::Pending) {
                throw ValidationException::withMessages(['proposal' => ['This metadata proposal can no longer be resolved.']]);
            }
            if ($resolution === PersonProposalStatus::Approved) {
                $this->applyMetadata($lockedPhoto, $lockedProposal);
            }
            $lockedProposal->update([
                'status' => $resolution,
                'resolved_by' => $actor->id,
                'resolved_at' => now(),
            ]);
            $this->audit->record(
                $resolution === PersonProposalStatus::Approved ? 'photo.metadata_confirmed' : 'photo.metadata_rejected',
                $lockedProposal,
                $actor,
                $request,
                ['photo_id' => $lockedPhoto->id, 'field' => $lockedProposal->field->value],
            );

            return $lockedProposal;
        });
    }

    /** @param array<string, mixed> $input */
    public function submitPhotoPerson(Photo $photo, User $actor, array $input, Request $request): PhotoPerson
    {
        return DB::transaction(function () use ($photo, $actor, $input, $request): PhotoPerson {
            $locked = Photo::query()->lockForUpdate()->findOrFail($photo->id);
            $person = Person::query()
                ->where('family_space_id', $locked->family_space_id)
                ->findOrFail((string) $input['person_id']);
            if (PhotoPerson::query()->where('photo_id', $locked->id)->where('person_id', $person->id)
                ->whereIn('status', [PersonProposalStatus::Pending, PersonProposalStatus::Approved])->exists()) {
                throw ValidationException::withMessages(['person_id' => ['This Person already has an active Photo association.']]);
            }
            $authoritative = $this->tenantContext->membership()->role->canManageMembers();
            $association = PhotoPerson::query()->create([
                'family_space_id' => $locked->family_space_id,
                'photo_id' => $locked->id,
                'person_id' => $person->id,
                'proposal_source' => 'human',
                'status' => $authoritative ? PersonProposalStatus::Approved : PersonProposalStatus::Pending,
                'proposed_by' => $actor->id,
                'resolved_by' => $authoritative ? $actor->id : null,
                'resolved_at' => $authoritative ? now() : null,
            ]);
            $this->audit->record(
                $authoritative ? 'photo.person_confirmed' : 'photo.person_proposed',
                $association,
                $actor,
                $request,
                ['photo_id' => $locked->id, 'person_id' => $person->id],
            );

            return $association->load('person:id,preferred_name');
        });
    }

    public function resolvePhotoPerson(
        Photo $photo,
        PhotoPerson $association,
        User $actor,
        PersonProposalStatus $resolution,
        Request $request,
    ): PhotoPerson {
        return DB::transaction(function () use ($photo, $association, $actor, $resolution, $request): PhotoPerson {
            $lockedPhoto = Photo::query()->lockForUpdate()->findOrFail($photo->id);
            $locked = PhotoPerson::query()->lockForUpdate()->findOrFail($association->id);
            if ($locked->photo_id !== $lockedPhoto->id || $locked->status !== PersonProposalStatus::Pending) {
                throw ValidationException::withMessages(['association' => ['This Person proposal can no longer be resolved.']]);
            }
            $locked->update(['status' => $resolution, 'resolved_by' => $actor->id, 'resolved_at' => now()]);
            $this->audit->record(
                $resolution === PersonProposalStatus::Approved ? 'photo.person_confirmed' : 'photo.person_rejected',
                $locked,
                $actor,
                $request,
                ['photo_id' => $lockedPhoto->id, 'person_id' => $locked->person_id],
            );

            return $locked->load('person:id,preferred_name');
        });
    }

    /** @param list<string> $labels */
    public function replaceTags(Photo $photo, User $actor, array $labels, Request $request): Photo
    {
        return DB::transaction(function () use ($photo, $actor, $labels, $request): Photo {
            $locked = Photo::query()->lockForUpdate()->findOrFail($photo->id);
            $this->syncTags($locked, $actor, $labels, true, $request);

            return $locked->load(['mediaUpload.uploader:id,name', 'tags:id,label']);
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function contentAttributes(array $input): array
    {
        $attributes = Arr::only($input, self::CONTENT_FIELDS);
        foreach (['caption', 'description', 'archive_source_description'] as $field) {
            if (array_key_exists($field, $attributes)) {
                $attributes[$field] = trim((string) ($attributes[$field] ?? '')) ?: null;
            }
        }

        return $attributes;
    }

    private function applyProvenance(Photo $photo, PhotoProvenanceProposal $proposal): void
    {
        [$personField, $descriptionField] = match ($proposal->role) {
            PhotoProvenanceRole::Photographer => ['photographer_person_id', 'photographer_description'],
            PhotoProvenanceRole::Scanner => ['scanner_person_id', 'scanner_description'],
            PhotoProvenanceRole::PhysicalOwner => ['physical_owner_person_id', 'physical_source_description'],
        };

        $photo->update([
            $personField => $proposal->clears_claim ? null : $proposal->person_id,
            $descriptionField => $proposal->clears_claim ? null : $proposal->description,
        ]);
    }

    private function applyMetadata(Photo $photo, PhotoMetadataProposal $proposal): void
    {
        if ($proposal->field === PhotoMetadataField::HistoricalDate) {
            $photo->update([
                'historical_date_precision' => $proposal->clears_claim ? null : $proposal->date_precision,
                'historical_date' => $proposal->clears_claim ? null : $proposal->date_value,
            ]);

            return;
        }

        $photo->update([
            'location_description' => $proposal->clears_claim ? null : $proposal->location_description,
        ]);
    }

    /** @param list<mixed> $labels */
    private function syncTags(
        Photo $photo,
        User $actor,
        array $labels,
        bool $audit,
        ?Request $request = null,
    ): void {
        $normalized = [];
        foreach ($labels as $label) {
            $display = preg_replace('/\s+/u', ' ', trim((string) $label)) ?? '';
            if ($display === '') {
                continue;
            }
            $key = mb_strtolower($display);
            $normalized[$key] ??= $display;
        }

        $tagIds = [];
        foreach ($normalized as $key => $display) {
            $tag = Tag::query()->firstOrCreate(
                ['family_space_id' => $photo->family_space_id, 'normalized_label' => $key],
                ['label' => $display, 'created_by' => $actor->id],
            );
            $tagIds[$tag->id] = [
                'family_space_id' => $photo->family_space_id,
                'added_by' => $actor->id,
                'created_at' => now(),
            ];
        }
        $photo->tags()->sync($tagIds);

        if ($audit && $request !== null) {
            $this->audit->record('photo.tags_changed', $photo, $actor, $request, [
                'tags' => array_values($normalized),
            ]);
        }
    }
}
