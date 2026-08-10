<?php

namespace App\Media;

use App\Enums\MediaVariantTransform;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class ImageMagickPresentationVariantGenerator implements PresentationVariantGenerator
{
    public function generate(string $canonicalPath, MediaVariantTransform $transform): GeneratedMediaVariant
    {
        $dimensions = $transform->dimensions();
        $path = sys_get_temp_dir().'/fambam-variant-'.bin2hex(random_bytes(16)).'.webp';

        try {
            $resize = "{$dimensions['width']}x{$dimensions['height']}";
            $geometry = $dimensions['crop'] ? "{$resize}^" : "{$resize}>";
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
                "{$canonicalPath}[0]",
                '-colorspace',
                'sRGB',
                '-resize',
                $geometry,
            ];
            if ($dimensions['crop']) {
                $command = [...$command, '-background', 'none', '-gravity', 'center', '-extent', $resize];
            }
            $command = [
                ...$command,
                '-strip',
                '-quality',
                (string) config('media.processing.variant_webp_quality'),
                '-alpha',
                'on',
                '-define',
                'webp:alpha-quality=100',
                '-define',
                'webp:method=6',
                "webp:{$path}",
            ];
            $this->run($command);
            chmod($path, 0600);

            $sha256 = hash_file('sha256', $path);
            $byteSize = filesize($path);
            [$width, $height] = $this->identifyDimensions($path);
            if ($sha256 === false || $byteSize === false) {
                throw new MediaProcessingFailed('The presentation variant could not be measured.');
            }

            return new GeneratedMediaVariant(
                $path,
                'webp',
                'image/webp',
                $sha256,
                $width,
                $height,
                $byteSize,
            );
        } catch (\Throwable $exception) {
            @unlink($path);
            throw $exception;
        }
    }

    /** @return array{int, int} */
    private function identifyDimensions(string $path): array
    {
        $output = trim($this->run([
            (string) config('media.validation.imagemagick_binary'),
            'identify',
            '-format',
            '%w %h',
            $path,
        ]));
        $parts = preg_split('/\s+/', $output);
        if ($parts === false || count($parts) !== 2 || ! ctype_digit($parts[0]) || ! ctype_digit($parts[1])) {
            throw new MediaProcessingFailed('The presentation variant dimensions could not be identified.');
        }

        return [(int) $parts[0], (int) $parts[1]];
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
            throw new MediaProcessingFailed('Presentation variant generation timed out.');
        } catch (ProcessFailedException) {
            throw new MediaProcessingFailed('Presentation variant generation failed.');
        }
    }
}
