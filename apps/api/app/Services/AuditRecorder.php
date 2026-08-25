<?php

namespace App\Services;

use App\Models\Album;
use App\Models\AlbumGrant;
use App\Models\AlbumPhoto;
use App\Models\AuditEvent;
use App\Models\EventAdmission;
use App\Models\FamilyCircle;
use App\Models\FamilyCirclePerson;
use App\Models\FamilyEvent;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\Invitation;
use App\Models\MediaUpload;
use App\Models\Person;
use App\Models\PersonAccountClaim;
use App\Models\PersonAccountLink;
use App\Models\PersonDetailProposal;
use App\Models\PersonMerge;
use App\Models\PersonMergeProposal;
use App\Models\PersonRelationship;
use App\Models\Photo;
use App\Models\PhotoComment;
use App\Models\PhotoCommentRevision;
use App\Models\PhotoMetadataProposal;
use App\Models\PhotoPerson;
use App\Models\PhotoProvenanceProposal;
use App\Models\PhotoReaction;
use App\Models\PhotoStory;
use App\Models\PhotoStoryRevision;
use App\Models\RelationshipProposal;
use App\Models\Tag;
use App\Models\User;
use App\Tenancy\TenantOperationContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AuditRecorder
{
    /** @param array<string, mixed> $metadata */
    public function record(
        string $action,
        Model $subject,
        ?User $actor = null,
        ?Request $request = null,
        array $metadata = [],
        ?TenantOperationContext $operationContext = null,
    ): void {
        if ($operationContext !== null) {
            $familySpaceId = $operationContext->familySpaceId;
            $correlationId = $operationContext->correlationId;
            $traceparent = $operationContext->traceparent;
        } else {
            $familySpaceId = $this->familySpaceId($subject, $metadata);
            $correlationId = (string) ($request?->attributes->get('correlation_id')
                ?? $request?->header('X-Correlation-ID')
                ?? Str::uuid());
            $traceparent = (string) ($request?->attributes->get('traceparent')
                ?? TenantOperationContext::newTraceparent());
        }

        $event = new AuditEvent([
            'family_space_id' => $familySpaceId,
            'actor_user_id' => $actor?->id,
            'correlation_id' => $correlationId,
            'traceparent' => $traceparent,
            'action' => $action,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => (string) $subject->getKey(),
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
        $event->created_at = now();

        AuditEvent::query()->insert($event->getAttributes());
    }

    /** @param array<string, mixed> $metadata */
    private function familySpaceId(Model $subject, array $metadata): ?string
    {
        if ($subject instanceof FamilySpace) {
            return $subject->id;
        }

        if ($subject instanceof FamilySpaceMembership
            || $subject instanceof Invitation
            || $subject instanceof MediaUpload
            || $subject instanceof Person
            || $subject instanceof PersonAccountClaim
            || $subject instanceof PersonAccountLink
            || $subject instanceof PersonDetailProposal
            || $subject instanceof PersonMerge
            || $subject instanceof PersonMergeProposal
            || $subject instanceof PersonRelationship
            || $subject instanceof RelationshipProposal
            || $subject instanceof FamilyCircle
            || $subject instanceof FamilyCirclePerson
            || $subject instanceof Photo
            || $subject instanceof PhotoMetadataProposal
            || $subject instanceof PhotoPerson
            || $subject instanceof PhotoProvenanceProposal
            || $subject instanceof Tag
            || $subject instanceof Album
            || $subject instanceof AlbumGrant
            || $subject instanceof AlbumPhoto
            || $subject instanceof PhotoStory
            || $subject instanceof PhotoStoryRevision
            || $subject instanceof PhotoComment
            || $subject instanceof PhotoCommentRevision
            || $subject instanceof PhotoReaction
            || $subject instanceof FamilyEvent
            || $subject instanceof EventAdmission) {
            return $subject->family_space_id;
        }

        $familySpaceId = $metadata['family_space_id'] ?? null;

        return is_string($familySpaceId) ? $familySpaceId : null;
    }
}
