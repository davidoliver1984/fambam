<?php

namespace App\Services;

use App\Enums\NotificationCategory;
use App\Jobs\ProcessNotificationCandidate;
use App\Models\Album;
use App\Models\AlbumPhoto;
use App\Models\ContributionGroup;
use App\Models\Photo;
use App\Tenancy\TenantOperationContext;
use Illuminate\Support\Facades\DB;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;

class EventContributionNotifier
{
    public function dispatch(Album $album, Photo $photo, TenantOperationContext $context): void
    {
        $photo->loadMissing('mediaUpload');
        $link = AlbumPhoto::query()->where('album_id', $album->id)->where('photo_id', $photo->id)->firstOrFail();
        $source = $link->id;
        $groupId = null;
        if ($photo->mediaUpload?->upload_batch_id !== null) {
            $group = ContributionGroup::query()->firstOrCreate([
                'family_space_id' => $album->family_space_id,
                'actor_user_id' => $context->actorUserId,
                'upload_batch_id' => $photo->mediaUpload->upload_batch_id,
                'album_id' => $album->id,
            ]);
            $source = $group->id;
            $groupId = $group->id;
        }
        $subject = [
            'photo_id' => $photo->id,
            'album_id' => $album->id,
            'album_photo_id' => $link->id,
            ...($groupId !== null ? ['contribution_group_id' => $groupId] : []),
        ];

        DB::afterCommit(function () use ($context, $source, $subject): void {
            $parent = Globals::propagator()->extract(['traceparent' => $context->traceparent]);
            $span = Globals::tracerProvider()
                ->getTracer('fambam-api')
                ->spanBuilder('event.contribution-notifications dispatch')
                ->setSpanKind(SpanKind::KIND_PRODUCER)
                ->setParent($parent)
                ->startSpan();
            $scope = $span->activate();

            try {
                $dispatchContext = $context;
                $spanContext = Span::getCurrent()->getContext();
                if ($spanContext->isValid()) {
                    $dispatchContext = new TenantOperationContext(
                        $context->familySpaceId,
                        $context->actorUserId,
                        $context->correlationId,
                        sprintf(
                            '00-%s-%s-%02x',
                            $spanContext->getTraceId(),
                            $spanContext->getSpanId(),
                            $spanContext->getTraceFlags(),
                        ),
                    );
                }
                ProcessNotificationCandidate::dispatch($dispatchContext->toArray(), NotificationCategory::Contribution, $source, $subject);
            } finally {
                $scope->detach();
                $span->end();
            }
        });
    }
}
