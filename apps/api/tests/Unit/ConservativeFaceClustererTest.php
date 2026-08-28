<?php

namespace Tests\Unit;

use App\FaceRecognition\ConservativeFaceClusterer;
use PHPUnit\Framework\TestCase;

class ConservativeFaceClustererTest extends TestCase
{
    public function test_complete_link_clustering_does_not_merge_a_similarity_chain(): void
    {
        $pairs = [
            ConservativeFaceClusterer::pairKey('a', 'b') => 0.1,
            ConservativeFaceClusterer::pairKey('b', 'c') => 0.1,
            ConservativeFaceClusterer::pairKey('a', 'c') => 0.3,
            ConservativeFaceClusterer::pairKey('d', 'e') => 0.05,
        ];

        $clusters = (new ConservativeFaceClusterer)->cluster(
            ['e', 'c', 'a', 'd', 'b'],
            $pairs,
            0.2,
        );

        $this->assertSame([['a', 'b'], ['d', 'e']], $clusters);
    }

    public function test_missing_pair_evidence_is_not_treated_as_a_match(): void
    {
        $clusters = (new ConservativeFaceClusterer)->cluster(
            ['a', 'b', 'c'],
            [ConservativeFaceClusterer::pairKey('a', 'b') => 0.1],
            0.2,
        );

        $this->assertSame([['a', 'b']], $clusters);
    }
}
