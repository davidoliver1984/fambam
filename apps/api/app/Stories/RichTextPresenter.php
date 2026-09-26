<?php

namespace App\Stories;

use App\Models\Person;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class RichTextPresenter
{
    public function __construct(
        private readonly RichTextRenderer $renderer,
        private readonly MentionAuthorizer $authorizer,
    ) {}

    /** @param array<string, mixed>|null $document */
    public function html(
        ?array $document,
        Model $owner,
        string $mentionTable,
        string $ownerColumn,
        string $familySlug,
        User $actor,
        Model $subject,
        string $vocabulary = RichTextDocument::FULL,
    ): ?string {
        if ($document === null) {
            return null;
        }

        $mentions = DB::table($mentionTable)
            ->where($ownerColumn, $owner->getKey())
            ->get(['mention_id', 'person_id'])
            ->keyBy('mention_id');
        $people = Person::query()->whereIn('id', $mentions->pluck('person_id'))->get()->keyBy('id');

        return $this->htmlWithPeople(
            $document,
            $mentions->mapWithKeys(fn (object $mention): array => [
                (string) $mention->mention_id => $people->get($mention->person_id),
            ])->filter()->all(),
            $familySlug,
            $actor,
            $subject,
            $vocabulary,
        );
    }

    /**
     * @param  array<string, mixed>|null  $document
     * @param  array<string, Person>  $peopleByMentionId
     */
    public function htmlWithPeople(
        ?array $document,
        array $peopleByMentionId,
        string $familySlug,
        User $actor,
        Model $subject,
        string $vocabulary = RichTextDocument::FULL,
    ): ?string {
        if ($document === null) {
            return null;
        }

        return $this->renderer->html($document, function (string $mentionId) use ($peopleByMentionId, $familySlug, $actor, $subject): ?array {
            $person = $peopleByMentionId[$mentionId] ?? null;
            if (! $person instanceof Person || ! $this->authorizer->allows($actor, $subject, $person)) {
                return null;
            }

            return [
                'label' => $person->preferred_name,
                'url' => "/families/{$familySlug}/people/{$person->id}",
            ];
        }, $vocabulary);
    }
}
