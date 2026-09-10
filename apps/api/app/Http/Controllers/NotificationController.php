<?php

namespace App\Http\Controllers;

use App\Enums\NotificationCategory;
use App\Enums\NotificationChannel;
use App\Models\Album;
use App\Models\FamilyNotification;
use App\Models\FamilySpace;
use App\Models\NotificationPreference;
use App\Models\Photo;
use App\Models\PhotoStory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class NotificationController extends Controller
{
    public function index(FamilySpace $familySpace, Request $request): JsonResponse
    {
        $rows = FamilyNotification::query()->where('recipient_user_id', $request->user()->id)->latest()->limit(50)->get();
        $data = $rows->filter(fn (FamilyNotification $row): bool => $this->visible($request, $row))
            ->map(fn (FamilyNotification $row): array => $this->payload($row))->values();

        return response()->json(['data' => $data]);
    }

    public function read(FamilySpace $familySpace, string $notification, Request $request): JsonResponse
    {
        $row = FamilyNotification::query()->where('recipient_user_id', $request->user()->id)->findOrFail($notification);
        abort_unless($this->visible($request, $row), 404);
        $row->update(['read_at' => $row->read_at ?? now()]);

        return response()->json(['data' => ['id' => $row->id, 'read_at' => $row->read_at?->toIso8601String()]]);
    }

    public function preferences(FamilySpace $familySpace, Request $request): JsonResponse
    {
        $stored = NotificationPreference::query()->where('user_id', $request->user()->id)->get()
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
        $data = $request->validate(['preferences' => 'required|array|max:8', 'preferences.*.category' => ['required', Rule::in(array_map(fn (NotificationCategory $category): string => $category->value, NotificationCategory::preferenceCases()))], 'preferences.*.channel' => ['required', Rule::enum(NotificationChannel::class)], 'preferences.*.enabled' => 'required|boolean']);
        foreach ($data['preferences'] as $preference) {
            NotificationPreference::query()->updateOrCreate(['family_space_id' => $familySpace->id, 'user_id' => $request->user()->id, 'category' => $preference['category'], 'channel' => $preference['channel']], ['enabled' => $preference['enabled']]);
        }

        return $this->preferences($familySpace, $request);
    }

    private function visible(Request $request, FamilyNotification $row): bool
    {
        if ($row->photo_id) {
            $photo = Photo::find($row->photo_id);
            if (! $photo || ! Gate::forUser($request->user())->allows('view', $photo)) {
                return false;
            }
        }
        if ($row->album_id) {
            $album = Album::find($row->album_id);
            if (! $album || ! Gate::forUser($request->user())->allows('view', $album)) {
                return false;
            }
        }
        if ($row->story_id) {
            $story = PhotoStory::query()->with('photo')->find($row->story_id);
            if ($story === null || ! Gate::forUser($request->user())->allows('view', $story->photo)) {
                return false;
            }
        }
        if ($row->family_export_id) {
            return $row->recipient_user_id === $request->user()->id;
        }

        return true;
    }

    /** @return array<string, mixed> */
    private function payload(FamilyNotification $row): array
    {
        $storyPhotoId = $row->story_id ? PhotoStory::query()->whereKey($row->story_id)->value('photo_id') : null;

        return [
            'id' => $row->id,
            'category' => $row->category->value,
            'photo_id' => $row->photo_id ?? $storyPhotoId,
            'album_id' => $row->album_id,
            'story_id' => $row->story_id,
            'person_id' => $row->person_id,
            'comment_id' => $row->comment_id,
            'family_export_id' => $row->family_export_id,
            'read_at' => $row->read_at?->toIso8601String(),
            'created_at' => $row->created_at->toIso8601String(),
        ];
    }
}
