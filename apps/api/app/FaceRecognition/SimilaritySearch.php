<?php

namespace App\FaceRecognition;

interface SimilaritySearch
{
    /**
     * @param  list<float>  $embedding
     * @return list<SimilarityMatch>
     */
    public function nearest(
        string $familySpaceId,
        EmbeddingSpaceIdentity $identity,
        array $embedding,
        int $limit,
    ): array;

    /**
     * @param  list<float>  $embedding
     * @return list<TrustedReferenceMatch>
     */
    public function nearestTrustedReferences(
        string $familySpaceId,
        EmbeddingSpaceIdentity $identity,
        array $embedding,
        int $limit,
    ): array;
}
