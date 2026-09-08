<?php

namespace App\Search;

use App\Models\PhotoStory;
use App\Models\User;
use App\Queries\AlbumQuery;
use App\Queries\PhotoQuery;
use App\Search\Summaries\AlbumSearchSummary;
use App\Search\Summaries\PhotoSearchSummary;
use App\Search\Summaries\PhotoStorySearchSummary;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DatabaseSearchService implements SearchService
{
    public function __construct(
        private readonly PhotoQuery $photos,
        private readonly AlbumQuery $albums,
        private readonly SearchCursorCodec $cursors,
    ) {}

    public function photos(SearchQuery $query, User $actor): SearchPage
    {
        $term = $this->term($query->term);
        $base = $this->photos->visibleTo($actor)->setEagerLoads([])->select([
            'photos.id', 'photos.media_upload_id', 'photos.caption', 'photos.description',
            'photos.location_description', 'photos.historical_date', 'photos.historical_date_precision',
        ]);
        if ($query->tagId !== null) {
            $base->whereHas('tags', fn ($tags) => $tags->where('tags.id', $query->tagId));
        }
        if ($query->dateFrom !== null || $query->dateTo !== null) {
            $base->whereNotNull('photos.historical_date')->whereNotIn(
                'photos.historical_date_precision',
                ['unknown'],
            );
            if (DB::getDriverName() === 'pgsql') {
                if ($query->dateFrom !== null) {
                    $base->whereDate('photos.historical_date_window_end', '>=', $query->dateFrom);
                }
                if ($query->dateTo !== null) {
                    $base->whereDate('photos.historical_date', '<=', $query->dateTo);
                }
            } else {
                $windowEnd = $this->sqliteWindowEnd();
                if ($query->dateFrom !== null) {
                    $base->whereRaw("{$windowEnd} >= ?", [$query->dateFrom]);
                }
                if ($query->dateTo !== null) {
                    $base->whereDate('photos.historical_date', '<=', $query->dateTo);
                }
            }
        }

        [$matchClass, $classBindings, $score, $scoreBindings, $matches, $matchBindings] =
            $this->photoExpressions($term);
        if ($term !== null) {
            $base->whereRaw($matches, $matchBindings);
        }
        $base->selectRaw("{$matchClass} AS match_class", $classBindings)
            ->selectRaw("{$score} AS match_score", $scoreBindings)
            ->selectRaw($this->photoTieBreaker().' AS sort_tie');

        $page = $this->page('photos', $base->toBase(), $query, 'sort_tie', 'desc');
        $people = $this->approvedPeopleForPhotos(array_column($page['rows'], 'id'));

        return new SearchPage(array_map(fn (array $row): PhotoSearchSummary => new PhotoSearchSummary(
            $row['id'],
            $row['media_upload_id'],
            $row['caption'],
            $row['description'],
            $row['location_description'],
            $row['historical_date'] === null ? null : [
                'precision' => $row['historical_date_precision'],
                'value' => $this->dateValue($row['historical_date'], $row['historical_date_precision']),
            ],
            $people[$row['id']] ?? [],
        ), $page['rows']), $page['next_cursor']);
    }

    public function albums(SearchQuery $query, User $actor): SearchPage
    {
        $term = $this->term($query->term);
        if ($term === null) {
            return new SearchPage([], null);
        }
        $base = $this->albums->visibleTo($actor)->setEagerLoads([])->select([
            'albums.id', 'albums.name', 'albums.description', 'albums.visibility', 'albums.event_id',
        ]);
        [$matchClass, $classBindings, $score, $scoreBindings, $matches, $matchBindings] =
            $this->simpleExpressions('albums', ['name', 'description'], 'name', $term);
        $base->whereRaw($matches, $matchBindings)
            ->selectRaw("{$matchClass} AS match_class", $classBindings)
            ->selectRaw("{$score} AS match_score", $scoreBindings)
            ->selectRaw('LOWER(albums.name) AS sort_tie');
        $page = $this->page('albums', $base->toBase(), $query, 'sort_tie', 'asc');

        return new SearchPage(array_map(fn (array $row): AlbumSearchSummary => new AlbumSearchSummary(
            $row['id'],
            $row['name'],
            $row['description'],
            $row['visibility'],
            $row['event_id'],
        ), $page['rows']), $page['next_cursor']);
    }

    public function stories(SearchQuery $query, User $actor): SearchPage
    {
        $term = $this->term($query->term);
        if ($term === null) {
            return new SearchPage([], null);
        }
        $visiblePhotoIds = $this->photos->visibleTo($actor)->setEagerLoads([])->select('photos.id');
        $base = PhotoStory::query()
            ->whereIn('photo_stories.photo_id', $visiblePhotoIds)
            ->join('photos', function ($join): void {
                $join->on('photos.id', '=', 'photo_stories.photo_id')
                    ->on('photos.family_space_id', '=', 'photo_stories.family_space_id');
            })
            ->select([
                'photo_stories.id', 'photo_stories.photo_id', 'photo_stories.body',
                'photo_stories.created_at', 'photos.media_upload_id', 'photos.caption as photo_caption',
            ]);
        [$matchClass, $classBindings, $score, $scoreBindings, $matches, $matchBindings] =
            $this->simpleExpressions('photo_stories', ['body'], null, $term);
        $base->whereRaw($matches, $matchBindings)
            ->selectRaw("{$matchClass} AS match_class", $classBindings)
            ->selectRaw("{$score} AS match_score", $scoreBindings)
            ->selectRaw($this->storyTieBreaker().' AS sort_tie');
        $page = $this->page('stories', $base->toBase(), $query, 'sort_tie', 'desc');

        return new SearchPage(array_map(fn (array $row): PhotoStorySearchSummary => new PhotoStorySearchSummary(
            $row['id'],
            $row['photo_id'],
            $row['photo_caption'],
            $row['media_upload_id'],
            Str::limit(trim((string) $row['body']), 240),
            (string) $row['created_at'],
        ), $page['rows']), $page['next_cursor']);
    }

    /**
     * @return array{rows: list<array<string, mixed>>, next_cursor: ?string}
     */
    private function page(
        string $group,
        Builder $base,
        SearchQuery $query,
        string $tieColumn,
        string $tieDirection,
    ): array {
        $ranked = DB::query()->fromSub($base, "ranked_{$group}");
        $cursor = $this->cursors->decode($query->cursor, $group);
        if ($cursor !== null) {
            $this->applyCursor($ranked, $cursor, $tieColumn, $tieDirection);
        }
        $ranked->orderByDesc('match_class')->orderByDesc('match_score')
            ->orderBy($tieColumn, $tieDirection)->orderBy('id');
        $rows = $ranked->limit($query->limit + 1)->get()->map(fn ($row) => (array) $row)->all();
        $hasMore = count($rows) > $query->limit;
        if ($hasMore) {
            array_pop($rows);
        }
        $last = $rows === [] ? null : $rows[array_key_last($rows)];
        $next = $hasMore && $last !== null ? $this->cursors->encode(new SearchCursor(
            $group,
            (int) $last['match_class'],
            (float) $last['match_score'],
            $last[$tieColumn] === null ? null : (string) $last[$tieColumn],
            (string) $last['id'],
        )) : null;

        return ['rows' => array_values($rows), 'next_cursor' => $next];
    }

    private function applyCursor(
        Builder $query,
        SearchCursor $cursor,
        string $tieColumn,
        string $tieDirection,
    ): void {
        $tieOperator = $tieDirection === 'desc' ? '<' : '>';
        $query->where(function (Builder $after) use ($cursor, $tieColumn, $tieOperator): void {
            $after->where('match_class', '<', $cursor->matchClass)
                ->orWhere(function (Builder $sameClass) use ($cursor, $tieColumn, $tieOperator): void {
                    $sameClass->whereRaw('CAST(match_class AS INTEGER) = ?', [$cursor->matchClass])
                        ->where(function (Builder $score) use ($cursor, $tieColumn, $tieOperator): void {
                            $score->whereRaw('CAST(match_score AS REAL) < ?', [$cursor->score])
                                ->orWhere(function (Builder $sameScore) use ($cursor, $tieColumn, $tieOperator): void {
                                    $sameScore->whereRaw('CAST(match_score AS REAL) = ?', [$cursor->score])
                                        ->where(function (Builder $tie) use ($cursor, $tieColumn, $tieOperator): void {
                                            $tie->where($tieColumn, $tieOperator, $cursor->tieBreaker)
                                                ->orWhere(function (Builder $sameTie) use ($cursor, $tieColumn): void {
                                                    $sameTie->where($tieColumn, $cursor->tieBreaker)
                                                        ->where('id', '>', $cursor->id);
                                                });
                                        });
                                });
                        });
                });
        });
    }

    /** @return array{string, list<mixed>, string, list<mixed>, string, list<mixed>} */
    private function photoExpressions(?string $term): array
    {
        if ($term === null) {
            return ['0', [], '0', [], '1 = 1', []];
        }
        $tag = 'EXISTS (SELECT 1 FROM photo_tag pt JOIN tags t ON t.id = pt.tag_id AND t.family_space_id = pt.family_space_id WHERE pt.photo_id = photos.id AND LOWER(t.label) LIKE ?)';
        $like = '%'.mb_strtolower($term).'%';
        if (DB::getDriverName() !== 'pgsql') {
            $text = "LOWER(COALESCE(photos.caption, '') || ' ' || COALESCE(photos.description, '') || ' ' || COALESCE(photos.archive_source_description, '') || ' ' || COALESCE(photos.location_description, '')) LIKE ?";
            $class = "CASE WHEN LOWER(COALESCE(photos.caption, '')) = LOWER(?) THEN 4 WHEN {$text} THEN 3 WHEN {$tag} THEN 1 ELSE 0 END";

            return [$class, [$term, $like, $like], '1', [], "({$text} OR {$tag})", [$like, $like]];
        }
        $full = "photos.search_vector @@ websearch_to_tsquery('simple'::regconfig, ?)";
        $fuzzy = "GREATEST(similarity(COALESCE(photos.caption, ''), ?), similarity(COALESCE(photos.location_description, ''), ?)) >= ?";
        $threshold = (float) config('search.trigram_threshold');
        $class = "CASE WHEN LOWER(COALESCE(photos.caption, '')) = LOWER(?) THEN 4 WHEN {$full} THEN 3 WHEN {$fuzzy} THEN 2 WHEN {$tag} THEN 1 ELSE 0 END";
        $score = "CASE WHEN {$full} THEN ts_rank(photos.search_vector, websearch_to_tsquery('simple'::regconfig, ?)) WHEN {$fuzzy} THEN GREATEST(similarity(COALESCE(photos.caption, ''), ?), similarity(COALESCE(photos.location_description, ''), ?)) ELSE 1 END";
        $matches = "({$full} OR {$fuzzy} OR {$tag})";

        return [
            $class, [$term, $term, $term, $term, $threshold, $like],
            $score, [$term, $term, $term, $term, $threshold, $term, $term],
            $matches, [$term, $term, $term, $threshold, $like],
        ];
    }

    /** @param list<string> $fields
     * @return array{string, list<mixed>, string, list<mixed>, string, list<mixed>}
     */
    private function simpleExpressions(string $table, array $fields, ?string $exactField, string $term): array
    {
        $like = '%'.mb_strtolower($term).'%';
        if (DB::getDriverName() !== 'pgsql') {
            $text = implode(" || ' ' || ", array_map(fn (string $field): string => "COALESCE({$table}.{$field}, '')", $fields));
            $match = "LOWER({$text}) LIKE ?";
            $exact = $exactField === null ? '0 = 1' : "LOWER(COALESCE({$table}.{$exactField}, '')) = LOWER(?)";
            $bindings = $exactField === null ? [$like] : [$term, $like];

            return ["CASE WHEN {$exact} THEN 4 WHEN {$match} THEN 3 ELSE 0 END", $bindings, '1', [], $match, [$like]];
        }
        $full = "{$table}.search_vector @@ websearch_to_tsquery('simple'::regconfig, ?)";
        $exact = $exactField === null ? 'FALSE' : "LOWER(COALESCE({$table}.{$exactField}, '')) = LOWER(?)";
        $fuzzy = $exactField === null ? 'FALSE' : "similarity(COALESCE({$table}.{$exactField}, ''), ?) >= ?";
        $threshold = (float) config('search.trigram_threshold');
        $classBindings = $exactField === null ? [$term] : [$term, $term, $term, $threshold];
        $score = "CASE WHEN {$full} THEN ts_rank({$table}.search_vector, websearch_to_tsquery('simple'::regconfig, ?))".
            ($exactField === null ? ' ELSE 1 END' : " WHEN {$fuzzy} THEN similarity(COALESCE({$table}.{$exactField}, ''), ?) ELSE 1 END");
        $scoreBindings = $exactField === null ? [$term, $term] : [$term, $term, $term, $threshold, $term];
        $matches = $exactField === null ? $full : "({$full} OR {$fuzzy})";
        $matchBindings = $exactField === null ? [$term] : [$term, $term, $threshold];

        return ["CASE WHEN {$exact} THEN 4 WHEN {$full} THEN 3 WHEN {$fuzzy} THEN 2 ELSE 0 END", $classBindings, $score, $scoreBindings, $matches, $matchBindings];
    }

    /** @param list<string> $photoIds
     * @return array<string, list<array{id: string, preferred_name: string}>>
     */
    private function approvedPeopleForPhotos(array $photoIds): array
    {
        if ($photoIds === []) {
            return [];
        }

        $rows = DB::table('photo_people')->whereIn('photo_people.photo_id', $photoIds)
            ->where('photo_people.status', 'approved')
            ->join('people', function ($join): void {
                $join->on('people.id', '=', 'photo_people.person_id')
                    ->on('people.family_space_id', '=', 'photo_people.family_space_id');
            })
            ->orderBy('people.preferred_name')
            ->get(['photo_people.photo_id', 'people.id as person_id', 'people.preferred_name']);
        $people = [];
        foreach ($rows as $result) {
            $row = (array) $result;
            $people[(string) $row['photo_id']][] = [
                'id' => (string) $row['person_id'],
                'preferred_name' => (string) $row['preferred_name'],
            ];
        }

        return $people;
    }

    private function term(?string $term): ?string
    {
        $term = $term === null ? '' : trim($term);

        return $term === '' ? null : $term;
    }

    private function sqliteWindowEnd(): string
    {
        return "CASE photos.historical_date_precision WHEN 'exact' THEN photos.historical_date WHEN 'approximate' THEN photos.historical_date WHEN 'month' THEN date(photos.historical_date, '+1 month', '-1 day') WHEN 'year' THEN date(photos.historical_date, '+1 year', '-1 day') WHEN 'decade' THEN date(photos.historical_date, '+10 years', '-1 day') ELSE NULL END";
    }

    private function photoTieBreaker(): string
    {
        return DB::getDriverName() === 'pgsql'
            ? "COALESCE(photos.historical_date::text, '0001-01-01')"
            : "COALESCE(photos.historical_date, '0001-01-01')";
    }

    private function storyTieBreaker(): string
    {
        return DB::getDriverName() === 'pgsql'
            ? "COALESCE(photo_stories.created_at::text, '0001-01-01')"
            : "COALESCE(photo_stories.created_at, '0001-01-01')";
    }

    private function dateValue(string $date, ?string $precision): ?string
    {
        return match ($precision) {
            'month' => substr($date, 0, 7),
            'year' => substr($date, 0, 4),
            'decade' => substr($date, 0, 4).'s',
            'unknown', null => null,
            default => substr($date, 0, 10),
        };
    }
}
