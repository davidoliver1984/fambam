<?php

namespace App\Queries;

use App\Enums\DatePrecision;
use App\Enums\FaceIdentityAssignmentStatus;
use App\Enums\RelationshipStatus;
use App\Models\Person;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

final class SettingsOverviewQuery
{
    public function __construct(
        private readonly PersonQuery $people,
        private readonly PhotoQuery $photos,
        private readonly AlbumQuery $albums,
        private readonly StoryQuery $stories,
    ) {}

    /**
     * @return array{
     *     counts: array{people: int, photos: int, albums: int, stories: int},
     *     archive_health: array{
     *         photos_dated: array{numerator: int, denominator: int, percentage: float|null},
     *         faces_identified: array{numerator: int, denominator: int, percentage: float|null},
     *         people_connected: array{numerator: int, denominator: int, percentage: float|null}
     *     }
     * }
     */
    public function forViewer(User $viewer): array
    {
        $people = $this->people->forCurrentFamilySpace()->setEagerLoads([]);
        $photos = $this->photos->visibleTo($viewer)->setEagerLoads([]);
        $albums = $this->albums->visibleTo($viewer)->setEagerLoads([]);
        $stories = $this->stories->visibleTo($viewer)->setEagerLoads([]);

        $peopleAggregate = $this->peopleAggregate($people);
        $photoAggregate = (clone $photos)
            ->selectRaw('COUNT(*) AS photos_count')
            ->selectRaw(
                'SUM(CASE WHEN photos.historical_date IS NOT NULL AND photos.historical_date_precision IS NOT NULL AND photos.historical_date_precision != ? THEN 1 ELSE 0 END) AS photos_dated_count',
                [DatePrecision::Unknown->value],
            );
        $albumAggregate = (clone $albums)->selectRaw('COUNT(*) AS albums_count');
        $storyAggregate = (clone $stories)->selectRaw('COUNT(*) AS stories_count');
        $faceAggregate = $this->faceAggregate($photos);

        $row = DB::query()
            ->fromSub($peopleAggregate, 'people_metrics')
            ->crossJoinSub($photoAggregate, 'photo_metrics')
            ->crossJoinSub($albumAggregate, 'album_metrics')
            ->crossJoinSub($storyAggregate, 'story_metrics')
            ->crossJoinSub($faceAggregate, 'face_metrics')
            ->first();

        $peopleCount = (int) ($row->people_count ?? 0);
        $photosCount = (int) ($row->photos_count ?? 0);
        $albumsCount = (int) ($row->albums_count ?? 0);
        $storiesCount = (int) ($row->stories_count ?? 0);
        $photosDatedCount = (int) ($row->photos_dated_count ?? 0);
        $facesCount = (int) ($row->faces_count ?? 0);
        $facesIdentifiedCount = (int) ($row->faces_identified_count ?? 0);
        $peopleConnectedCount = (int) ($row->people_connected_count ?? 0);

        return [
            'counts' => [
                'people' => $peopleCount,
                'photos' => $photosCount,
                'albums' => $albumsCount,
                'stories' => $storiesCount,
            ],
            'archive_health' => [
                'photos_dated' => $this->metric($photosDatedCount, $photosCount),
                'faces_identified' => $this->metric($facesIdentifiedCount, $facesCount),
                'people_connected' => $this->metric($peopleConnectedCount, $peopleCount),
            ],
        ];
    }

    /** @param Builder<Person> $people */
    private function peopleAggregate(Builder $people): QueryBuilder
    {
        $visiblePeople = (clone $people)->select('people.id');
        $subjects = DB::query()->from('person_relationships')
            ->select('person_relationships.subject_person_id AS person_id')
            ->where('person_relationships.status', RelationshipStatus::Confirmed->value)
            ->whereIn('person_relationships.subject_person_id', (clone $people)->select('people.id'))
            ->whereIn('person_relationships.related_person_id', (clone $people)->select('people.id'));
        $related = DB::query()->from('person_relationships')
            ->select('person_relationships.related_person_id AS person_id')
            ->where('person_relationships.status', RelationshipStatus::Confirmed->value)
            ->whereIn('person_relationships.subject_person_id', (clone $people)->select('people.id'))
            ->whereIn('person_relationships.related_person_id', (clone $people)->select('people.id'));
        $connectedPeople = $subjects->union($related);

        return DB::query()->fromSub($visiblePeople, 'visible_people')
            ->leftJoinSub(
                $connectedPeople,
                'connected_people',
                'connected_people.person_id',
                '=',
                'visible_people.id',
            )
            ->selectRaw('COUNT(*) AS people_count')
            ->selectRaw('SUM(CASE WHEN connected_people.person_id IS NOT NULL THEN 1 ELSE 0 END) AS people_connected_count');
    }

    /** @param Builder<Photo> $photos */
    private function faceAggregate(Builder $photos): QueryBuilder
    {
        $identity = config('image-analysis.identity');
        $visiblePhotos = (clone $photos)->select([
            'photos.family_space_id',
            'photos.media_upload_id',
        ]);

        return DB::query()->fromSub($visiblePhotos, 'visible_photos')
            ->join('media_uploads', function ($join): void {
                $join->on('media_uploads.id', '=', 'visible_photos.media_upload_id')
                    ->on('media_uploads.family_space_id', '=', 'visible_photos.family_space_id');
            })
            ->join('face_analysis_runs', function ($join) use ($identity): void {
                $join->on('face_analysis_runs.media_upload_id', '=', 'visible_photos.media_upload_id')
                    ->on('face_analysis_runs.family_space_id', '=', 'visible_photos.family_space_id')
                    ->on('face_analysis_runs.canonical_sha256', '=', 'media_uploads.canonical_sha256')
                    ->where('face_analysis_runs.provider', $identity['provider'])
                    ->where('face_analysis_runs.model_identifier', $identity['model_identifier'])
                    ->where('face_analysis_runs.model_weight_checksum', $identity['model_weight_checksum'])
                    ->where('face_analysis_runs.config_hash', $identity['config_hash']);
            })
            ->join('face_observations', function ($join): void {
                $join->on('face_observations.face_analysis_run_id', '=', 'face_analysis_runs.id')
                    ->on('face_observations.family_space_id', '=', 'visible_photos.family_space_id');
            })
            ->leftJoin('face_identity_assignments', function ($join): void {
                $join->on('face_identity_assignments.face_observation_id', '=', 'face_observations.id')
                    ->on('face_identity_assignments.family_space_id', '=', 'visible_photos.family_space_id')
                    ->where('face_identity_assignments.status', FaceIdentityAssignmentStatus::Approved->value);
            })
            ->selectRaw('COUNT(DISTINCT face_observations.id) AS faces_count')
            ->selectRaw('COUNT(DISTINCT CASE WHEN face_identity_assignments.id IS NOT NULL THEN face_observations.id END) AS faces_identified_count');
    }

    /** @return array{numerator: int, denominator: int, percentage: float|null} */
    private function metric(int $numerator, int $denominator): array
    {
        return [
            'numerator' => $numerator,
            'denominator' => $denominator,
            'percentage' => $denominator === 0 ? null : round(($numerator / $denominator) * 100, 2),
        ];
    }
}
