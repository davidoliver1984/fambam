<?php

namespace App\Services;

use App\Enums\FamilySpaceRole;
use App\Enums\MembershipState;
use App\Enums\NotificationCategory;
use App\Enums\NotificationChannel;
use App\Enums\NotificationOutcome;
use App\Enums\PersonProposalStatus;
use App\Jobs\FinalizeContributionNotification;
use App\Models\Album;
use App\Models\AlbumPhoto;
use App\Models\ContributionGroup;
use App\Models\EventAdmission;
use App\Models\FamilyEvent;
use App\Models\FamilyNotification;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\LoveNotificationGroup;
use App\Models\LoveNotificationGroupActor;
use App\Models\NotificationCandidate;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\PersonAccountLink;
use App\Models\Photo;
use App\Models\PhotoComment;
use App\Models\PhotoPerson;
use App\Models\Story;
use App\Models\StoryComment;
use App\Models\User;
use App\Notifications\FamilyActivityNotification;
use App\Tenancy\DatabaseTenantContext;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantOperationContext;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class NotificationManager
{
    public function __construct(
        private readonly DatabaseTenantContext $databaseContext,
        private readonly TenantContext $tenantContext,
        private readonly Dispatcher $dispatcher,
    ) {}

    /**
     * @param  array{family_space_id:string,actor_user_id:int,correlation_id:string,traceparent:string}  $rawContext
     * @param  array<string, mixed>  $subject
     */
    public function process(array $rawContext, NotificationCategory $category, string $sourceActionId, array $subject): void
    {
        $recipientIds = DB::transaction(function () use ($rawContext, $category, $subject): array {
            $context = $this->establish($rawContext);

            return $this->recipients($category, $subject)
                ->reject(fn (User $recipient): bool => $recipient->id === $context->actorUserId)
                ->sortBy('id')->pluck('id')->values()->all();
        });
        foreach ($recipientIds as $recipientId) {
            $this->evaluate($rawContext, (int) $recipientId, $category, $sourceActionId, $subject, false);
        }
    }

    /**
     * @param  array{family_space_id:string,actor_user_id:int,correlation_id:string,traceparent:string}  $rawContext
     * @param  array<string, mixed>  $subject
     */
    public function registerContribution(array $rawContext, string $groupId, array $subject): void
    {
        $terminal = DB::transaction(function () use ($rawContext, $groupId): bool {
            $context = $this->establish($rawContext);
            $terminal = NotificationCandidate::query()
                ->where('category', NotificationCategory::Contribution->value)
                ->where('source_action_id', $groupId)
                ->whereNotNull('evaluated_at')
                ->exists();
            if ($terminal) {
                return true;
            }
            foreach ($this->recipients(NotificationCategory::Contribution, ['album_id' => ContributionGroup::query()->findOrFail($groupId)->album_id]) as $recipient) {
                if ($recipient->id === $context->actorUserId) {
                    continue;
                }
                NotificationCandidate::query()->firstOrCreate(
                    $this->identity($context, $recipient, NotificationCategory::Contribution, $groupId),
                    $this->pendingOutcomes(),
                );
            }
            DB::afterCommit(
                fn () => FinalizeContributionNotification::dispatch($rawContext, $groupId)
                    ->delay(now()->addSeconds(60))
            );

            return false;
        });
        if ($terminal) {
            $this->process($rawContext, NotificationCategory::Contribution, (string) $subject['album_photo_id'], $subject);
        }
    }

    /** @param array{family_space_id:string,actor_user_id:int,correlation_id:string,traceparent:string} $rawContext */
    public function finalizeContribution(array $rawContext, string $groupId): void
    {
        $pending = DB::transaction(function () use ($rawContext, $groupId): ?array {
            $this->establish($rawContext);
            $group = ContributionGroup::query()->find($groupId);
            if ($group === null) {
                return null;
            }
            $links = AlbumPhoto::query()->where('album_id', $group->album_id)
                ->whereHas('photo.mediaUpload', fn ($query) => $query->where('user_id', $group->actor_user_id)->where('upload_batch_id', $group->upload_batch_id))
                ->with('photo')->orderBy('position')->get();
            $photo = $links->first()?->photo;

            return [
                'subject' => ['album_id' => $group->album_id, ...($photo ? ['photo_id' => $photo->id] : [])],
                'recipients' => NotificationCandidate::query()
                    ->where('category', NotificationCategory::Contribution->value)
                    ->where('source_action_id', $groupId)->whereNull('evaluated_at')
                    ->pluck('recipient_user_id')->all(),
            ];
        });
        if ($pending === null) {
            return;
        }
        foreach ($pending['recipients'] as $recipientId) {
            $this->evaluate($rawContext, (int) $recipientId, NotificationCategory::Contribution, $groupId, $pending['subject'], true);
        }
    }

    /** @param array{family_space_id:string,actor_user_id:int,correlation_id:string,traceparent:string} $rawContext */
    public function finalizeLove(array $rawContext, string $groupId): void
    {
        $pending = DB::transaction(function () use ($rawContext, $groupId): ?array {
            $this->establish($rawContext);
            $group = LoveNotificationGroup::query()->find($groupId);
            if ($group === null) {
                return null;
            }

            return [
                'subject' => array_filter([
                    'photo_id' => $group->photo_id,
                    'album_id' => $group->album_id,
                    'event_id' => $group->event_id,
                    'story_id' => $group->story_id,
                ]),
                'recipients' => NotificationCandidate::query()
                    ->where('category', NotificationCategory::Love->value)
                    ->where('source_action_id', $groupId)->whereNull('evaluated_at')
                    ->pluck('recipient_user_id')->all(),
            ];
        });
        if ($pending === null) {
            return;
        }
        foreach ($pending['recipients'] as $recipientId) {
            $this->evaluate($rawContext, (int) $recipientId, NotificationCategory::Love,
                $groupId, $pending['subject'], true);
        }
    }

    /**
     * @param  array{family_space_id:string,actor_user_id:int,correlation_id:string,traceparent:string}  $rawContext
     * @param  array<string, mixed>  $subject
     */
    private function evaluate(array $rawContext, int $recipientId, NotificationCategory $category, string $sourceActionId, array $subject, bool $allowPending): void
    {
        $prepared = DB::transaction(function () use ($rawContext, $recipientId, $category, $sourceActionId, $subject, $allowPending): ?array {
            $context = $this->establish($rawContext);
            $recipient = User::query()->whereNull('revoked_at')->find($recipientId);
            if ($recipient === null) {
                return null;
            }
            $candidate = NotificationCandidate::query()->lockForUpdate()->firstOrCreate($this->identity($context, $recipient, $category, $sourceActionId), $this->pendingOutcomes());
            $message = $this->message($category, $sourceActionId, $subject);
            if ($candidate->evaluated_at !== null) {
                $delivery = NotificationDelivery::query()->where($this->identity($context, $recipient, $category, $sourceActionId))->whereIn('status', ['pending', 'failed'])->first();

                return $delivery === null ? null : ['recipient' => $recipient, 'delivery_id' => $delivery->id,
                    'url' => $this->url($context->familySpaceId, $subject), 'message' => $message];
            }
            if (! $candidate->wasRecentlyCreated && ! $allowPending) {
                return null;
            }
            if ($category === NotificationCategory::Love && $message === null) {
                $candidate->update(['in_app_outcome' => NotificationOutcome::SkippedNoActors,
                    'email_outcome' => NotificationOutcome::SkippedNoActors, 'evaluated_at' => now()]);

                return null;
            }
            if (! $this->authorized($context->familySpaceId, $recipient, $subject)) {
                $candidate->update([...$this->skippedAuthorization(), 'evaluated_at' => now()]);

                return null;
            }
            $typed = $this->typedSubject($category, $subject);
            $inApp = $this->enabled($context->familySpaceId, $recipient->id, $category, NotificationChannel::InApp);
            $email = $this->enabled($context->familySpaceId, $recipient->id, $category, NotificationChannel::Email);
            $notification = $inApp ? FamilyNotification::query()->create(['family_space_id' => $context->familySpaceId, 'recipient_user_id' => $recipient->id, 'category' => $category, 'source_action_id' => $sourceActionId, ...$typed]) : null;
            $delivery = null;
            if ($email) {
                $delivery = NotificationDelivery::query()->create(['family_space_id' => $context->familySpaceId, 'recipient_user_id' => $recipient->id, 'category' => $category, 'source_action_id' => $sourceActionId, 'notification_id' => $notification?->id, 'channel' => 'mail', 'status' => 'pending', ...$typed]);
            }
            $candidate->update(['in_app_outcome' => $inApp ? NotificationOutcome::Created : NotificationOutcome::SkippedPreference, 'email_outcome' => $email ? NotificationOutcome::Created : NotificationOutcome::SkippedPreference, 'evaluated_at' => now()]);

            return $delivery === null ? null : ['recipient' => $recipient, 'delivery_id' => $delivery->id,
                'url' => $this->url($context->familySpaceId, $subject), 'message' => $message];
        });
        if ($prepared === null) {
            return;
        }
        /** @var User $recipient */
        $recipient = $prepared['recipient'];
        $authorized = DB::transaction(function () use ($rawContext, $recipient, $subject): bool {
            $context = $this->establish($rawContext);

            return $this->authorized($context->familySpaceId, $recipient, $subject);
        });
        if (! $authorized) {
            $this->updateDelivery($rawContext, $prepared['delivery_id'], ['status' => 'failed', 'attempted_at' => now(), 'failure_reason' => 'authorization_withdrawn']);

            return;
        }
        try {
            $this->dispatcher->send($recipient, new FamilyActivityNotification($prepared['message'], $prepared['url']));
            $this->updateDelivery($rawContext, $prepared['delivery_id'], ['status' => 'sent', 'attempted_at' => now(), 'sent_at' => now(), 'failure_reason' => null]);
        } catch (\Throwable $exception) {
            $this->updateDelivery($rawContext, $prepared['delivery_id'], ['status' => 'failed', 'attempted_at' => now(), 'failure_reason' => 'delivery_failed']);
            throw $exception;
        }
    }

    /**
     * @param  array{family_space_id:string,actor_user_id:int,correlation_id:string,traceparent:string}  $rawContext
     * @param  array<string, mixed>  $values
     */
    private function updateDelivery(array $rawContext, string $deliveryId, array $values): void
    {
        DB::transaction(function () use ($rawContext, $deliveryId, $values): void {
            $this->establish($rawContext);
            NotificationDelivery::query()->whereKey($deliveryId)->update($values);
        });
    }

    /**
     * @param  array<string, mixed>  $subject
     * @return array<string, string>
     */
    private function typedSubject(NotificationCategory $category, array $subject): array
    {
        return match ($category) {
            NotificationCategory::Comment => isset($subject['story_comment_id'])
                ? ['story_id' => $subject['story_id'], 'story_comment_id' => $subject['story_comment_id']]
                : ['photo_id' => $subject['photo_id'], 'album_id' => $subject['album_id'], 'comment_id' => $subject['comment_id']],
            NotificationCategory::Contribution => ['album_id' => $subject['album_id']],
            NotificationCategory::Story => ['story_id' => $subject['story_id']],
            NotificationCategory::Identity => ['photo_id' => $subject['photo_id'], 'person_id' => $subject['person_id']],
            NotificationCategory::Export => ['family_export_id' => $subject['family_export_id']],
            NotificationCategory::Attendance => ['event_id' => $subject['event_id']],
            NotificationCategory::Love => $subject,
        };
    }

    private function enabled(string $familyId, int $userId, NotificationCategory $category, NotificationChannel $channel): bool
    {
        $stored = NotificationPreference::query()->where(['family_space_id' => $familyId, 'user_id' => $userId, 'category' => $category->value, 'channel' => $channel->value])->value('enabled');

        return $stored === null ? $channel === NotificationChannel::InApp || in_array($category, [NotificationCategory::Comment, NotificationCategory::Identity], true) : (bool) $stored;
    }

    /**
     * @param  array<string, mixed>  $subject
     * @return Collection<int, User>
     */
    private function recipients(NotificationCategory $category, array $subject): Collection
    {
        $ids = collect();
        if ($category === NotificationCategory::Comment) {
            if (isset($subject['story_comment_id'])) {
                $ids = collect([Story::find($subject['story_id'])?->author_id])
                    ->merge(StoryComment::query()->where('story_id', $subject['story_id'])->pluck('author_id'))
                    ->merge(PersonAccountLink::query()->whereIn('person_id', DB::table('story_comment_person_mentions')
                        ->where('story_comment_id', $subject['story_comment_id'])->pluck('person_id'))->pluck('user_id'));
            } else {
                $ids = collect([Album::find($subject['album_id'])?->created_by, Photo::find($subject['photo_id'])?->created_by])
                    ->merge(PhotoComment::query()->where('photo_id', $subject['photo_id'])->where('album_id', $subject['album_id'])->pluck('author_id'))
                    ->merge(PersonAccountLink::query()->whereIn('person_id', DB::table('photo_comment_person_mentions')
                        ->where('photo_comment_id', $subject['comment_id'])->pluck('person_id'))->pluck('user_id'));
            }
        } elseif ($category === NotificationCategory::Story) {
            $story = Story::query()->find($subject['story_id']);
            $ids = match (true) {
                $story?->photo_id !== null => collect([Photo::find($story->photo_id)?->created_by])->merge(PersonAccountLink::query()->whereIn('person_id', PhotoPerson::query()->where('photo_id', $story->photo_id)->where('status', PersonProposalStatus::Approved->value)->pluck('person_id'))->pluck('user_id')),
                $story?->person_id !== null => PersonAccountLink::query()->where('person_id', $story->person_id)->pluck('user_id'),
                $story?->album_id !== null => collect([Album::find($story->album_id)?->created_by]),
                $story?->event_id !== null => collect([FamilyEvent::find($story->event_id)?->created_by]),
                default => collect(),
            };
        } elseif ($category === NotificationCategory::Identity) {
            $ids = PersonAccountLink::query()->where('person_id', $subject['person_id'])->pluck('user_id');
        } elseif ($category === NotificationCategory::Contribution) {
            $album = Album::find($subject['album_id']);
            $ids = collect([$album?->created_by]);
            if ($album?->event_id !== null) {
                $event = FamilyEvent::query()->find($album->event_id);
                $cutoff = now()->subDays((int) config('events.admission_lifetime_days'));
                if ($event !== null) {
                    $ids->push($event->created_by);
                }
                $ids = $ids->merge(EventAdmission::query()->where('event_id', $album->event_id)->whereNull('revoked_at')->where('admitted_at', '>', $cutoff)->with('membership:id,user_id')->get()->pluck('membership.user_id'))
                    ->merge(FamilySpaceMembership::query()->where('family_space_id', $album->family_space_id)->where('state', MembershipState::Active->value)->whereIn('role', [FamilySpaceRole::Owner->value, FamilySpaceRole::Administrator->value])->pluck('user_id'));
            }
        }

        if ($category === NotificationCategory::Attendance) {
            $ids = collect([FamilyEvent::query()->find($subject['event_id'])?->created_by]);
        }

        return User::query()->whereIn('id', $ids->filter()->unique())->whereNull('revoked_at')->get();
    }

    /** @param array<string, mixed> $subject */
    private function authorized(string $familyId, User $user, array $subject): bool
    {
        $membership = FamilySpaceMembership::query()->where('family_space_id', $familyId)->where('user_id', $user->id)->where('state', MembershipState::Active->value)->first();
        $familySpace = FamilySpace::query()->find($familyId);
        if ($membership === null || $familySpace === null) {
            return false;
        }
        $this->tenantContext->establish($familySpace, $membership, $user);
        try {
            if (isset($subject['photo_id'])) {
                $photo = Photo::find($subject['photo_id']);
                if ($photo === null || ! Gate::forUser($user)->allows('view', $photo)) {
                    return false;
                }
            }
            if (isset($subject['album_id'])) {
                $album = Album::find($subject['album_id']);
                if ($album === null || ! Gate::forUser($user)->allows('view', $album)) {
                    return false;
                }
            }
            if (isset($subject['story_id'])) {
                $story = Story::find($subject['story_id']);
                if ($story === null || ! Gate::forUser($user)->allows('view', $story)) {
                    return false;
                }
            }

            if (isset($subject['event_id'])) {
                $event = FamilyEvent::query()->find($subject['event_id']);
                if ($event === null || ! Gate::forUser($user)->allows('view', $event)) {
                    return false;
                }
            }

            return true;
        } finally {
            $this->tenantContext->clear();
        }
    }

    /** @param array{family_space_id:string,actor_user_id:int,correlation_id:string,traceparent:string} $rawContext */
    private function establish(array $rawContext): TenantOperationContext
    {
        $context = TenantOperationContext::fromArray($rawContext);
        $this->databaseContext->establishUser($context->actorUserId);
        $this->databaseContext->establishFamilySpace($context->familySpaceId);

        return $context;
    }

    /** @return array<string, mixed> */
    private function identity(TenantOperationContext $context, User $recipient, NotificationCategory $category, string $sourceActionId): array
    {
        return ['family_space_id' => $context->familySpaceId, 'recipient_user_id' => $recipient->id, 'category' => $category, 'source_action_id' => $sourceActionId];
    }

    /** @return array<string, NotificationOutcome> */
    private function pendingOutcomes(): array
    {
        return ['in_app_outcome' => NotificationOutcome::Pending, 'email_outcome' => NotificationOutcome::Pending];
    }

    /** @return array<string, NotificationOutcome> */
    private function skippedAuthorization(): array
    {
        return ['in_app_outcome' => NotificationOutcome::SkippedAuthorization, 'email_outcome' => NotificationOutcome::SkippedAuthorization];
    }

    /** @param array<string, mixed> $subject */
    private function message(NotificationCategory $category, string $sourceActionId, array $subject): ?string
    {
        if ($category === NotificationCategory::Love) {
            $actors = User::query()->whereIn('id', LoveNotificationGroupActor::query()
                ->where('group_id', $sourceActionId)->orderBy('actor_user_id')->pluck('actor_user_id'))
                ->orderBy('id')->get();
            if ($actors->isEmpty()) {
                return null;
            }
            $target = match (true) {
                isset($subject['photo_id']) => 'Photo',
                isset($subject['album_id']) => 'Album',
                isset($subject['event_id']) => 'Event',
                default => 'Story',
            };
            $others = $actors->count() - 1;

            return $actors->first()->name.($others > 0 ? " and {$others} others" : '')." loved your {$target}.";
        }

        return match ($category) {
            NotificationCategory::Comment => 'Someone joined a family conversation.',
            NotificationCategory::Contribution => 'New photographs were added.',
            NotificationCategory::Story => 'A new family story was added.',
            NotificationCategory::Identity => 'Your identity was confirmed in a photograph.',
            NotificationCategory::Export => 'Your fambam export status changed.',
            NotificationCategory::Attendance => 'Someone responded to an Event invitation.',
        };
    }

    /** @param array<string, mixed> $subject */
    private function url(string $familyId, array $subject): string
    {
        $slug = FamilySpace::query()->whereKey($familyId)->value('slug');
        $base = rtrim((string) config('app.web_url'), '/')."/families/{$slug}";

        return isset($subject['event_id']) ? $base.'/events/'.$subject['event_id']
            : (isset($subject['story_id']) ? $base.'/stories/'.$subject['story_id']
            : (isset($subject['photo_id']) ? $base.'/photos/'.$subject['photo_id']
                : (isset($subject['album_id']) ? $base.'/albums/'.$subject['album_id'] : $base)));
    }
}
