<?php

namespace App\Media;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class ImageMagickDifferenceHasher implements PerceptualHasher
{
    public function hash(string $canonicalPath): string
    {
        try {
            $process = new Process([
                (string) config('media.validation.imagemagick_binary'),
                '-limit',
                'memory',
                (string) config('media.validation.memory_limit'),
                '-limit',
                'map',
                (string) config('media.validation.map_limit'),
                '-limit',
                'disk',
                (string) config('media.validation.disk_limit'),
                "{$canonicalPath}[0]",
                '-auto-orient',
                '-colorspace',
                'Gray',
                '-resize',
                '9x8!',
                '-depth',
                '8',
                'gray:-',
            ]);
            $process->setTimeout((float) config('media.processing.timeout_seconds'));
            $process->mustRun();
            $bytes = $process->getOutput();
        } catch (ProcessTimedOutException) {
            throw new MediaProcessingFailed('Perceptual hashing timed out.');
        } catch (ProcessFailedException) {
            throw new MediaProcessingFailed('Perceptual hashing failed.');
        }

        if (strlen($bytes) !== 72) {
            throw new MediaProcessingFailed('Perceptual hashing produced an invalid luminance sample.');
        }

        $pixels = array_values(unpack('C*', $bytes));
        $hash = '';
        for ($row = 0; $row < 8; $row++) {
            $byte = 0;
            for ($column = 0; $column < 8; $column++) {
                $offset = ($row * 9) + $column;
                $byte = ($byte << 1) | ($pixels[$offset] > $pixels[$offset + 1] ? 1 : 0);
            }
            $hash .= sprintf('%02x', $byte);
        }

        return $hash;
    }
}
