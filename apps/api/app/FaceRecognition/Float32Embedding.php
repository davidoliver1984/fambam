<?php

namespace App\FaceRecognition;

use InvalidArgumentException;

final class Float32Embedding
{
    /** @return list<float> */
    public function decode(string $bytes, int $dimension): array
    {
        if ($dimension < 1 || strlen($bytes) !== $dimension * 4) {
            throw new InvalidArgumentException('Float32 embedding byte length does not match its dimension.');
        }

        $decoded = unpack('g*', $bytes);
        if ($decoded === false || count($decoded) !== $dimension) {
            throw new InvalidArgumentException('Float32 embedding could not be decoded.');
        }

        $values = [];
        foreach ($decoded as $value) {
            if (! is_finite($value)) {
                throw new InvalidArgumentException('Float32 embedding contains a non-finite value.');
            }
            $values[] = (float) $value;
        }

        return $values;
    }

    /** @param list<float> $embedding */
    public function vectorLiteral(array $embedding): string
    {
        if ($embedding === []) {
            throw new InvalidArgumentException('A similarity embedding must not be empty.');
        }

        return '['.implode(',', array_map(function (float $value): string {
            if (! is_finite($value)) {
                throw new InvalidArgumentException('A similarity embedding must contain only finite values.');
            }

            return sprintf('%.9g', $value);
        }, $embedding)).']';
    }
}
