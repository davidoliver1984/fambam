<?php

namespace App\Jobs;

use App\Enums\FamilySpaceRole;
use App\Enums\MembershipState;
use App\Models\EventAdmission;
use App\Models\EventNotificationDelivery;
use App\Models\FamilyEvent;
use App\Models\FamilySpaceMembership;
use App\Models\Photo;
use App\Models\User;
use App\Notifications\EventPhotoContributed;
use App\Services\AuditRecorder;
use App\Tenancy\DatabaseTenantContext;
use App\Tenancy\TenantOperationContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class SendEventContributionNotifications implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    /** @param array{family_space_id: string, actor_user_id: int, correlation_id: string, traceparent: string} $context */
    public function __construct(
        public array $context,
        public string $eventId,
        public string $photoId,
    ) {}

    public function uniqueId(): string
    {
        return "event-contribution:{$this->photoId}";
    }

    public function handle(
        DatabaseTenantContext $databaseContext,
        AuditRecorder $audit,
        Dispatcher $notifications,
    ): void {
        $context = TenantOperationContext::fromArray($this->context);

        [$event, $photo, $recipients] = DB::transaction(function () use ($context, $databaseContext): array {
            $databaseContext->establishUser($context->actorUserId);
            $databaseContext->establishFamilySpace($context->familySpaceId);
            $event = FamilyEvent::query()->with('familySpace:id,slug')->find($this->eventId);
            $photo = Photo::query()->with(['creator:id,name', 'mediaUpload.uploader:id,name'])->find($this->photoId);
            if ($event === null || $photo === null) {
                return [null, null, collect()];
            }

            $cutoff = now()->subDays((int) config('events.admission_lifetime_days'));
            $admittedUserIds = EventAdmission::query()
                ->where('event_id', $event->id)
                ->whereNull('revoked_at')
                ->where('admitted_at', '>', $cutoff)
                ->whereHas('membership', fn ($membership) => $membership
                    ->where('state', MembershipState::Active->value))
                ->with('membership:id,user_id')
                ->get()->pluck('membership.user_id');
            $managerUserIds = FamilySpaceMembership::query()
                ->where('family_space_id', $event->family_space_id)
                ->where('state', MembershipState::Active->value)
                ->whereIn('role', [FamilySpaceRole::Owner->value, FamilySpaceRole::Administrator->value])
                ->pluck('user_id');
            $creatorActive = FamilySpaceMembership::query()
                ->where('family_space_id', $event->family_space_id)
                ->where('user_id', $event->created_by)
                ->where('state', MembershipState::Active->value)
                ->exists();
            $userIds = $admittedUserIds->merge($managerUserIds)
                ->when($creatorActive, fn ($ids) => $ids->push($event->created_by))
                ->reject(fn ($id): bool => (int) $id === $context->actorUserId)
                ->unique()->values();

            return [$event, $photo, User::query()->whereIn('id', $userIds)
                ->whereNull('revoked_at')->get()];
        });

        if (! $event instanceof FamilyEvent || ! $photo instanceof Photo) {
            return;
        }

        $contributorName = $photo->mediaUpload->uploader->name;
        $url = rtrim((string) config('app.web_url'), '/')
            ."/families/{$event->familySpace->slug}/events/{$event->id}";
        $sentCount = 0;
        foreach ($recipients->sortBy('id') as $recipient) {
            $delivery = EventNotificationDelivery::query()->firstOrCreate([
                'family_space_id' => $event->family_space_id,
                'event_id' => $event->id,
                'photo_id' => $photo->id,
                'user_id' => $recipient->id,
            ]);
            if ($delivery->sent_at !== null) {
                continue;
            }

            $notifications->send($recipient, new EventPhotoContributed($event->name, $contributorName, $url));
            $delivery->update(['sent_at' => now()]);
            $sentCount++;
        }

        DB::transaction(function () use ($context, $databaseContext, $event, $photo, $sentCount, $audit): void {
            $databaseContext->establishUser($context->actorUserId);
            $databaseContext->establishFamilySpace($context->familySpaceId);
            $audit->record('event.contribution_notified', $event, metadata: [
                'photo_id' => $photo->id,
                'recipient_count' => $sentCount,
            ], operationContext: $context);
        });
    }
}
