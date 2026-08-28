<?php

namespace App\FaceRecognition;

use App\Models\FaceObservation;
use App\Tenancy\DatabaseTenantContext;
use App\Tenancy\TenantOperationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class FaceEmbeddingProjectionManager
{
    public function __construct(
        private readonly Float32Embedding $embeddings,
        private readonly DatabaseTenantContext $tenant,
    ) {}

    public function project(FaceObservation $observation): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $bytes = $this->binary($observation->getRawOriginal('embedding'));
        $embedding = $this->embeddings->decode($bytes, $observation->embedding_dimension);
        $vector = $this->embeddings->vectorLiteral($embedding);
        $version = (string) config('face-recognition.projection_version');
        if ($version === '' || strlen($version) > 40) {
            throw new InvalidArgumentException('The face-recognition projection version must contain 1-40 characters.');
        }

        DB::statement(<<<'SQL'
INSERT INTO face_embedding_projections (
    id, family_space_id, face_observation_id, projection_version,
    source_checksum, embedding_dimension, vector, created_at, updated_at
) VALUES (?, ?, ?, ?, ?, ?, CAST(? AS vector), CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
ON CONFLICT (face_observation_id) DO UPDATE SET
    projection_version = EXCLUDED.projection_version,
    source_checksum = EXCLUDED.source_checksum,
    embedding_dimension = EXCLUDED.embedding_dimension,
    vector = EXCLUDED.vector,
    updated_at = CURRENT_TIMESTAMP
SQL, [
            (string) Str::ulid(),
            $observation->family_space_id,
            $observation->id,
            $version,
            hash('sha256', $bytes),
            $observation->embedding_dimension,
            $vector,
        ]);
    }

    /** @param list<string> $faceObservationIds */
    public function rebuild(TenantOperationContext $context, array $faceObservationIds = []): int
    {
        return DB::transaction(function () use ($context, $faceObservationIds): int {
            $this->tenant->establishUser($context->actorUserId);
            $this->tenant->establishFamilySpace($context->familySpaceId);
            $query = FaceObservation::query()->orderBy('id');
            if ($faceObservationIds !== []) {
                $query->whereIn('id', $faceObservationIds);
            }
            $observations = $query->get();
            if ($faceObservationIds !== [] && $observations->count() !== count(array_unique($faceObservationIds))) {
                throw new InvalidArgumentException('Every requested FaceObservation must belong to the selected Family Space.');
            }
            foreach ($observations as $observation) {
                $this->project($observation);
            }

            return $observations->count();
        });
    }

    private function binary(mixed $value): string
    {
        if (is_resource($value)) {
            $contents = stream_get_contents($value);
            if ($contents !== false) {
                return $contents;
            }
        }
        if (is_string($value)) {
            return $value;
        }

        throw new InvalidArgumentException('FaceObservation embedding is not readable binary data.');
    }
}
