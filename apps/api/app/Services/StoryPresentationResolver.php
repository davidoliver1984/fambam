<?php

namespace App\Services;

use App\Models\Album;
use App\Models\FamilyEvent;
use App\Models\Person;
use App\Models\Photo;
use App\Models\User;
use App\Queries\PhotoQuery;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class StoryPresentationResolver
{
    public function __construct(
        private readonly PhotoQuery $photos,
        private readonly MediaDeliveryManager $delivery,
        private readonly PresentationThumbnailService $thumbnails,
    ) {}

    /** @return array{type: string, id: string, label: string} */
    public function subject(Model $subject): array
    {
        [$type, $label] = match (true) {
            $subject instanceof Person => ['person', $subject->preferred_name],
            $subject instanceof Album => ['album', $subject->name],
            $subject instanceof FamilyEvent => ['event', $subject->name],
            $subject instanceof Photo => ['photo', $subject->caption ?? $subject->mediaUpload?->client_filename],
            default => throw new \LogicException('Story has an unsupported subject.'),
        };
        if (! is_string($label) || trim($label) === '') {
            throw new \LogicException('Story subject has no presentation label.');
        }

        return ['type' => $type, 'id' => (string) $subject->getKey(), 'label' => $label];
    }

    /**
     * @return array{source_type: string, photo_id: string|null, url: string, method: string, expires_at: string|null}|null
     */
    public function hero(Model $subject, User $viewer): ?array
    {
        if ($subject instanceof Person) {
            $url = $this->thumbnails->forPeople([$subject->id], $viewer)[$subject->id] ?? null;

            return $url === null ? null : [
                'source_type' => 'person_portrait',
                'photo_id' => null,
                'url' => $url,
                'method' => 'GET',
                'expires_at' => null,
            ];
        }

        $photo = match (true) {
            $subject instanceof Photo => $subject,
            $subject instanceof Album => $subject->coverPhoto,
            $subject instanceof FamilyEvent => $this->eventPreview($subject, $viewer),
            default => null,
        };
        if (! $photo instanceof Photo || Gate::forUser($viewer)->denies('view', $photo)) {
            return null;
        }

        try {
            $authorization = $this->delivery->photoPresentation($photo);
        } catch (NotFoundHttpException|RuntimeException|\InvalidArgumentException) {
            return null;
        }

        return [
            'source_type' => match (true) {
                $subject instanceof Album => 'album_cover',
                $subject instanceof FamilyEvent => 'event_preview',
                default => 'photo',
            },
            'photo_id' => $photo->id,
            'url' => $authorization->url,
            'method' => 'GET',
            'expires_at' => $authorization->expiresAt->toAtomString(),
        ];
    }

    private function eventPreview(FamilyEvent $event, User $viewer): ?Photo
    {
        return $this->photos->visibleTo($viewer)->setEagerLoads([])
            ->where(function ($query) use ($event): void {
                $query->where('primary_event_id', $event->id)
                    ->orWhereHas('albums', fn ($albums) => $albums->where('albums.event_id', $event->id));
            })
            ->with(['mediaUpload', 'activeVersion'])
            ->orderByRaw('historical_date IS NULL')
            ->orderBy('historical_date')
            ->orderBy('id')
            ->first();
    }
}
