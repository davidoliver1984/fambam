<?php

namespace Tests\Unit;

use App\Media\PerceptualHashDistance;
use Tests\TestCase;

class PerceptualHashTest extends TestCase
{
    public function test_hamming_distance_counts_changed_bits_and_rejects_invalid_hashes(): void
    {
        $this->assertSame(0, PerceptualHashDistance::hamming('0000000000000000', '0000000000000000'));
        $this->assertSame(1, PerceptualHashDistance::hamming('0000000000000000', '0000000000000001'));
        $this->assertSame(64, PerceptualHashDistance::hamming('0000000000000000', 'ffffffffffffffff'));

        $this->expectException(\InvalidArgumentException::class);
        PerceptualHashDistance::hamming('not-a-hash', '0000000000000000');
    }
}
