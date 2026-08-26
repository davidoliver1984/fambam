<?php

namespace App\Media;

final class PerceptualHashDistance
{
    public static function hamming(string $left, string $right): int
    {
        if (! preg_match('/^[0-9a-f]{16}$/', $left) || ! preg_match('/^[0-9a-f]{16}$/', $right)) {
            throw new \InvalidArgumentException('Perceptual hashes must be 64-bit lowercase hexadecimal values.');
        }

        $leftBytes = hex2bin($left);
        $rightBytes = hex2bin($right);
        $distance = 0;

        for ($index = 0; $index < 8; $index++) {
            $distance += substr_count(decbin(ord($leftBytes[$index]) ^ ord($rightBytes[$index])), '1');
        }

        return $distance;
    }
}
