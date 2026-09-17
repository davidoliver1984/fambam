<?php

namespace App\Services;

use App\Enums\NotificationCategory;
use App\Enums\NotificationOutcome;
use App\Jobs\FinalizeLoveNotification;
use App\Models\Album;
use App\Models\FamilyEvent;
use App\Models\LoveNotificationGroup;
use App\Models\LoveNotificationGroupActor;
use App\Models\NotificationCandidate;
use App\Models\Photo;
use App\Models\PhotoReaction;
use App\Models\Story;
use App\Models\User;
use App\Tenancy\TenantOperationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class LoveNotificationManager
{
    public function added(Photo|Album|FamilyEvent|Story $target, User $actor, Request $request): void
    {
        $recipientId = $target instanceof Story ? $target->author_id : $target->created_by;
        if ($recipientId === null || $recipientId === $actor->id) {
            return;
        }
        $context = TenantOperationContext::fromRequest($target->familySpace, $actor, $request)->toArray();
        DB::transaction(function () use ($target, $actor, $recipientId, $context): void {
            $this->lockTarget($target);
            $group = $this->openGroup($target);
            if ($group === null) {
                $group = LoveNotificationGroup::query()->create([
                    'family_space_id' => $target->family_space_id,
                    $this->column($target) => $target->getKey(),
                ]);
                NotificationCandidate::query()->create([
                    'family_space_id' => $target->family_space_id,
                    'recipient_user_id' => $recipientId,
                    'category' => NotificationCategory::Love,
                    'source_action_id' => $group->id,
                    'in_app_outcome' => NotificationOutcome::Pending,
                    'email_outcome' => NotificationOutcome::Pending,
                ]);
                $groupId = $group->id;
                DB::afterCommit(fn () => FinalizeLoveNotification::dispatch($context, $groupId)
                    ->delay(now()->addSeconds(60)));
            }
            LoveNotificationGroupActor::query()->firstOrCreate([
                'family_space_id' => $target->family_space_id,
                'group_id' => $group->id,
                'actor_user_id' => $actor->id,
            ]);
        });
    }

    public function removed(Photo|Album|FamilyEvent|Story $target, User $actor): void
    {
        DB::transaction(function () use ($target, $actor): void {
            $this->lockTarget($target);
            if ($target instanceof Photo && PhotoReaction::query()
                ->where('photo_id', $target->id)->where('user_id', $actor->id)
                ->where('reaction', 'love')->exists()) {
                return;
            }
            $group = $this->openGroup($target);
            if ($group !== null) {
                LoveNotificationGroupActor::query()->where('group_id', $group->id)
                    ->where('actor_user_id', $actor->id)->delete();
            }
        });
    }

    private function openGroup(Photo|Album|FamilyEvent|Story $target): ?LoveNotificationGroup
    {
        foreach (LoveNotificationGroup::query()->where($this->column($target), $target->getKey())
            ->whereIn('id', NotificationCandidate::query()->select('source_action_id')
                ->where('category', NotificationCategory::Love->value)->whereNull('evaluated_at'))
            ->orderByDesc('created_at')->orderByDesc('id')->get() as $group) {
            $candidate = NotificationCandidate::query()->where('source_action_id', $group->id)
                ->where('category', NotificationCategory::Love->value)->lockForUpdate()->first();
            if ($candidate !== null && $candidate->evaluated_at === null) {
                return $group;
            }
        }

        return null;
    }

    private function lockTarget(Photo|Album|FamilyEvent|Story $target): void
    {
        $target::query()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();
    }

    private function column(Photo|Album|FamilyEvent|Story $target): string
    {
        return match (true) {
            $target instanceof Photo => 'photo_id',
            $target instanceof Album => 'album_id',
            $target instanceof FamilyEvent => 'event_id',
            $target instanceof Story => 'story_id',
        };
    }
}
