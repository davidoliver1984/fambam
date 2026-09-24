<?php

namespace App\Http\Controllers;

use App\Enums\NotificationCategory;
use App\Enums\NotificationChannel;
use App\Models\FamilyNotification;
use App\Models\FamilySpace;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Services\NotificationPresentationBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class NotificationController extends Controller
{
    public function __construct(private readonly NotificationPresentationBuilder $presentations) {}

    public function index(FamilySpace $familySpace, Request $request): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $request->user();
        $rows = FamilyNotification::query()->where('family_space_id', $familySpace->id)
            ->where('recipient_user_id', $viewer->id)
            ->with([
                'photo', 'album.coverPhoto', 'story.photo', 'person', 'event', 'familyExport',
            ])->latest()->limit(50)->get()
            ->filter(fn (FamilyNotification $row): bool => $this->visible($request, $row))->values();
        $presentations = $this->presentations->build($familySpace, $viewer, $rows);
        $data = $rows->map(fn (FamilyNotification $row): array => $this->payload(
            $row,
            $presentations[$row->id],
        ));

        return response()->json(['data' => $data]);
    }

    public function read(FamilySpace $familySpace, string $notification, Request $request): JsonResponse
    {
        $row = FamilyNotification::query()->where('family_space_id', $familySpace->id)
            ->where('recipient_user_id', $request->user()->id)
            ->with(['photo', 'album', 'story', 'event'])->findOrFail($notification);
        abort_unless($this->visible($request, $row), 404);
        $row->update(['read_at' => $row->read_at ?? now()]);

        return response()->json(['data' => ['id' => $row->id, 'read_at' => $row->read_at?->toIso8601String()]]);
    }

    public function preferences(FamilySpace $familySpace, Request $request): JsonResponse
    {
        $stored = NotificationPreference::query()->where('family_space_id', $familySpace->id)
            ->where('user_id', $request->user()->id)->get()
            ->keyBy(fn (NotificationPreference $preference): string => $preference->category->value.':'.$preference->channel->value);
        $data = [];
        foreach (NotificationCategory::preferenceCases() as $category) {
            foreach (NotificationChannel::cases() as $channel) {
                $key = $category->value.':'.$channel->value;
                $data[] = ['category' => $category->value, 'channel' => $channel->value, 'enabled' => isset($stored[$key]) ? $stored[$key]->enabled : ($channel === NotificationChannel::InApp || in_array($category, [NotificationCategory::Comment, NotificationCategory::Identity], true))];
            }
        }

        return response()->json(['data' => $data]);
    }

    public function updatePreferences(FamilySpace $familySpace, Request $request): JsonResponse
    {
        $data = $request->validate(['preferences' => 'required|array|max:12', 'preferences.*.category' => ['required', Rule::in(array_map(fn (NotificationCategory $category): string => $category->value, NotificationCategory::preferenceCases()))], 'preferences.*.channel' => ['required', Rule::enum(NotificationChannel::class)], 'preferences.*.enabled' => 'required|boolean']);
        foreach ($data['preferences'] as $preference) {
            NotificationPreference::query()->updateOrCreate(['family_space_id' => $familySpace->id, 'user_id' => $request->user()->id, 'category' => $preference['category'], 'channel' => $preference['channel']], ['enabled' => $preference['enabled']]);
        }

        return $this->preferences($familySpace, $request);
    }

    private function visible(Request $request, FamilyNotification $row): bool
    {
        if ($row->photo_id) {
            $photo = $row->photo;
            if (! $photo || ! Gate::forUser($request->user())->allows('view', $photo)) {
                return false;
            }
        }
        if ($row->album_id) {
            $album = $row->album;
            if (! $album || ! Gate::forUser($request->user())->allows('view', $album)) {
                return false;
            }
        }
        if ($row->story_id) {
            $story = $row->story;
            if ($story === null || ! Gate::forUser($request->user())->allows('view', $story)) {
                return false;
            }
        }
        if ($row->family_export_id) {
            return $row->recipient_user_id === $request->user()->id;
        }

        if ($row->event_id) {
            $event = $row->event;
            if ($event === null || ! Gate::forUser($request->user())->allows('view', $event)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $presentation
     * @return array<string, mixed>
     */
    private function payload(FamilyNotification $row, array $presentation): array
    {
        $storyPhotoId = $row->story?->photo_id;

        return [
            'id' => $row->id,
            'category' => $row->category->value,
            'photo_id' => $row->photo_id ?? $storyPhotoId,
            'album_id' => $row->album_id,
            'story_id' => $row->story_id,
            'person_id' => $row->person_id,
            'comment_id' => $row->comment_id,
            'story_comment_id' => $row->story_comment_id,
            'family_export_id' => $row->family_export_id,
            'event_id' => $row->event_id,
            'read_at' => $row->read_at?->toIso8601String(),
            'created_at' => $row->created_at->toIso8601String(),
            'presentation' => $presentation,
        ];
    }
}
