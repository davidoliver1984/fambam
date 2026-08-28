<?php

namespace App\FaceRecognition;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PostgresSimilaritySearch implements SimilaritySearch
{
    public function __construct(private readonly Float32Embedding $embeddings) {}

    public function nearest(
        string $familySpaceId,
        EmbeddingSpaceIdentity $identity,
        array $embedding,
        int $limit,
    ): array {
        if (DB::getDriverName() !== 'pgsql') {
            throw new InvalidArgumentException('Face similarity search requires PostgreSQL.');
        }
        $maximum = (int) config('face_recognition.similarity_max_results');
        if ($limit < 1 || $limit > $maximum) {
            throw new InvalidArgumentException("Similarity result limit must be between 1 and {$maximum}.");
        }

        $vector = $this->embeddings->vectorLiteral($embedding);
        $rows = DB::select(<<<'SQL'
SELECT
    projections.face_observation_id,
    projections.vector <=> CAST(? AS vector) AS cosine_distance
FROM face_embedding_projections projections
JOIN face_observations observations
  ON observations.id = projections.face_observation_id
 AND observations.family_space_id = projections.family_space_id
JOIN face_analysis_runs runs
  ON runs.id = observations.face_analysis_run_id
 AND runs.family_space_id = observations.family_space_id
WHERE projections.family_space_id = ?
  AND projections.projection_version = ?
  AND projections.embedding_dimension = ?
  AND projections.source_checksum = encode(sha256(observations.embedding), 'hex')
  AND runs.provider = ?
  AND runs.model_identifier = ?
  AND runs.model_weight_checksum = ?
  AND runs.config_hash = ?
ORDER BY projections.vector <=> CAST(? AS vector), projections.face_observation_id
LIMIT ?
SQL, [
            $vector,
            $familySpaceId,
            (string) config('face_recognition.projection_version'),
            count($embedding),
            $identity->provider,
            $identity->modelIdentifier,
            $identity->modelWeightChecksum,
            $identity->configHash,
            $vector,
            $limit,
        ]);

        return array_map(
            fn (object $row): SimilarityMatch => new SimilarityMatch(
                (string) $row->face_observation_id,
                (float) $row->cosine_distance,
            ),
            $rows,
        );
    }

    public function nearestTrustedReferences(
        string $familySpaceId,
        EmbeddingSpaceIdentity $identity,
        array $embedding,
        int $limit,
    ): array {
        $vector = $this->validatedVector($embedding, $limit);
        $rows = DB::select(<<<'SQL'
SELECT
    projections.face_observation_id,
    assignments.person_id,
    projections.vector <=> CAST(? AS vector) AS cosine_distance
FROM face_embedding_projections projections
JOIN face_observations observations
  ON observations.id = projections.face_observation_id
 AND observations.family_space_id = projections.family_space_id
JOIN face_analysis_runs runs
  ON runs.id = observations.face_analysis_run_id
 AND runs.family_space_id = observations.family_space_id
JOIN face_identity_assignments assignments
  ON assignments.face_observation_id = observations.id
 AND assignments.family_space_id = observations.family_space_id
 AND assignments.status = 'approved'
JOIN people
  ON people.id = assignments.person_id
 AND people.family_space_id = assignments.family_space_id
 AND people.deleted_at IS NULL
WHERE projections.family_space_id = ?
  AND projections.projection_version = ?
  AND projections.embedding_dimension = ?
  AND projections.source_checksum = encode(sha256(observations.embedding), 'hex')
  AND runs.provider = ?
  AND runs.model_identifier = ?
  AND runs.model_weight_checksum = ?
  AND runs.config_hash = ?
ORDER BY projections.vector <=> CAST(? AS vector), assignments.person_id, projections.face_observation_id
LIMIT ?
SQL, [
            $vector,
            $familySpaceId,
            (string) config('face_recognition.projection_version'),
            count($embedding),
            $identity->provider,
            $identity->modelIdentifier,
            $identity->modelWeightChecksum,
            $identity->configHash,
            $vector,
            $limit,
        ]);

        return array_map(
            fn (object $row): TrustedReferenceMatch => new TrustedReferenceMatch(
                (string) $row->face_observation_id,
                (string) $row->person_id,
                (float) $row->cosine_distance,
            ),
            $rows,
        );
    }

    /** @param list<float> $embedding */
    private function validatedVector(array $embedding, int $limit): string
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new InvalidArgumentException('Face similarity search requires PostgreSQL.');
        }
        $maximum = (int) config('face_recognition.similarity_max_results');
        if ($limit < 1 || $limit > $maximum) {
            throw new InvalidArgumentException("Similarity result limit must be between 1 and {$maximum}.");
        }

        return $this->embeddings->vectorLiteral($embedding);
    }
}
