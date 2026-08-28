<?php

namespace App\FaceRecognition;

use InvalidArgumentException;

final class ConservativeFaceClusterer
{
    /**
     * @param  list<string>  $candidateIds
     * @param  array<string, float>  $pairDistances  Canonical pair keys from pairKey().
     * @return list<list<string>>
     */
    public function cluster(array $candidateIds, array $pairDistances, float $maximumDistance): array
    {
        if (! is_finite($maximumDistance) || $maximumDistance < 0 || $maximumDistance > 2) {
            throw new InvalidArgumentException('Clustering cosine distance must be between 0 and 2.');
        }

        $candidateIds = array_values(array_unique($candidateIds));
        sort($candidateIds, SORT_STRING);
        $clusters = [];

        foreach ($candidateIds as $candidateId) {
            foreach ($clusters as $index => $cluster) {
                if ($this->fitsEveryMember($candidateId, $cluster, $pairDistances, $maximumDistance)) {
                    $clusters[$index][] = $candidateId;

                    continue 2;
                }
            }
            $clusters[] = [$candidateId];
        }

        return array_values(array_filter($clusters, fn (array $cluster): bool => count($cluster) >= 2));
    }

    public static function pairKey(string $firstId, string $secondId): string
    {
        return strcmp($firstId, $secondId) < 0
            ? "{$firstId}\0{$secondId}"
            : "{$secondId}\0{$firstId}";
    }

    /**
     * @param  list<string>  $cluster
     * @param  array<string, float>  $pairDistances
     */
    private function fitsEveryMember(
        string $candidateId,
        array $cluster,
        array $pairDistances,
        float $maximumDistance,
    ): bool {
        foreach ($cluster as $memberId) {
            $distance = $pairDistances[self::pairKey($candidateId, $memberId)] ?? null;
            if ($distance === null || ! is_finite($distance) || $distance > $maximumDistance) {
                return false;
            }
        }

        return true;
    }
}
