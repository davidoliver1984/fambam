<?php

namespace Tests\Unit;

use App\FaceRecognition\Float32Embedding;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class Float32EmbeddingTest extends TestCase
{
    public function test_it_decodes_little_endian_float32_bytes_deterministically(): void
    {
        $embedding = (new Float32Embedding)->decode(pack('g*', 1.0, -0.5, 0.25), 3);

        $this->assertSame([1.0, -0.5, 0.25], $embedding);
        $this->assertSame('[1,-0.5,0.25]', (new Float32Embedding)->vectorLiteral($embedding));
    }

    public function test_it_rejects_dimension_mismatch_and_non_finite_values(): void
    {
        $codec = new Float32Embedding;

        try {
            $codec->decode(pack('g', 1.0), 2);
            $this->fail('Dimension mismatch unexpectedly decoded.');
        } catch (InvalidArgumentException) {
        }

        $this->expectException(InvalidArgumentException::class);
        $codec->vectorLiteral([INF]);
    }
}
