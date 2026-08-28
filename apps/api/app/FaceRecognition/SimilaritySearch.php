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
}
