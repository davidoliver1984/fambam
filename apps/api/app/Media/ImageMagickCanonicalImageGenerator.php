<?php

namespace App\Media;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class ImageMagickCanonicalImageGenerator implements CanonicalImageGenerator
{
    public function generate(string $sourcePath): GeneratedCanonical
    {
        $transparent = $this->hasMeaningfulTransparency($sourcePath);
        $extension = $transparent ? 'png' : 'jpg';
        $mimeType = $transparent ? 'image/png' : 'image/jpeg';
        $path = sys_get_temp_dir().'/fambam-canonical-'.bin2hex(random_bytes(16)).'.'.$extension;

        try {
            $command = [
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
                "{$sourcePath}[0]",
                '-auto-orient',
                '-colorspace',
                'sRGB',
            ];
            if ($transparent) {
                $command = [...$command, '-strip', '-define', 'png:exclude-chunk=date,time', "png:{$path}"];
            } else {
                $command = [
                    ...$command,
                    '-background',
                    'white',
                    '-alpha',
                    'remove',
                    '-alpha',
                    'off',
                    '-strip',
                    '-quality',
                    (string) config('media.processing.canonical_jpeg_quality'),
                    '-interlace',
                    'Plane',
                    "jpeg:{$path}",
                ];
            }
            $this->run($command);
            chmod($path, 0600);
            $checksum = hash_file('sha256', $path);
            if ($checksum === false) {
                throw new MediaProcessingFailed('The canonical checksum could not be calculated.');
            }

            return new GeneratedCanonical($path, $extension, $mimeType, $checksum);
        } catch (\Throwable $exception) {
            @unlink($path);
            throw $exception;
        }
    }

    private function hasMeaningfulTransparency(string $path): bool
    {
        $output = trim($this->run([
            (string) config('media.validation.imagemagick_binary'),
            'identify',
            '-format',
            '%[opaque]',
            "{$path}[0]",
        ]));

        return strcasecmp($output, 'False') === 0;
    }

    /** @param list<string> $command */
    private function run(array $command): string
    {
        try {
            $process = new Process($command);
            $process->setTimeout((float) config('media.processing.timeout_seconds'));
            $process->mustRun();

            return $process->getOutput();
        } catch (ProcessTimedOutException) {
            throw new MediaProcessingFailed('Canonical generation timed out.');
        } catch (ProcessFailedException) {
            throw new MediaProcessingFailed('Canonical generation failed.');
        }
    }
}
