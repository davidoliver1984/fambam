<?php

namespace App\PhotoEditing;

use App\Media\GeneratedMediaVariant;
use App\Media\MediaProcessingFailed;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class ImageMagickPhotoEditRenderer implements PhotoEditRenderer
{
    public function render(string $canonicalPath, array $recipe, ?array $restore): GeneratedMediaVariant
    {
        $path = sys_get_temp_dir().'/fambam-photo-edit-'.bin2hex(random_bytes(16)).'.webp';
        try {
            $dimensions = getimagesize($canonicalPath);
            if ($dimensions === false) {
                throw new MediaProcessingFailed('The canonical Photo could not be decoded.');
            }
            $args = [$this->binary(), '-limit', 'memory', (string) config('media.validation.memory_limit'),
                '-limit', 'map', (string) config('media.validation.map_limit'),
                '-limit', 'disk', (string) config('media.validation.disk_limit'),
                "{$canonicalPath}[0]", '-colorspace', 'sRGB'];
            if ($recipe['crop'] !== null) {
                $crop = $recipe['crop'];
                $width = max(1, (int) round($dimensions[0] * $crop['width']));
                $height = max(1, (int) round($dimensions[1] * $crop['height']));
                $x = (int) round($dimensions[0] * $crop['x']);
                $y = (int) round($dimensions[1] * $crop['y']);
                $args = [...$args, '-crop', "{$width}x{$height}+{$x}+{$y}", '+repage'];
            }
            if ($recipe['rotate_degrees'] !== 0) {
                $args = [...$args, '-rotate', (string) $recipe['rotate_degrees']];
            }
            if ($recipe['flip_horizontal']) {
                $args[] = '-flop';
            }
            if ($recipe['flip_vertical']) {
                $args[] = '-flip';
            }
            if ((float) $recipe['straighten_degrees'] !== 0.0) {
                $args = [...$args, '-background', 'none', '-rotate', (string) $recipe['straighten_degrees']];
            }
            $adjust = $recipe['adjustments'];
            $args = [...$args, '-brightness-contrast', "{$adjust['brightness']}x{$adjust['contrast']}",
                '-modulate', '100,'.(100 + (float) $adjust['saturation']).',100'];
            if ((float) $adjust['warmth'] !== 0.0) {
                $tint = $adjust['warmth'] > 0 ? '#ed9b58' : '#6faed9';
                $args = [...$args, '-fill', $tint, '-colorize', (abs((float) $adjust['warmth']) / 10).'%'];
            }
            $filter = $recipe['filter'];
            if ($filter['name'] !== null && (float) $filter['intensity'] > 0) {
                $strength = (float) $filter['intensity'];
                $args = match ($filter['name']) {
                    'black_and_white' => [...$args, '-modulate', '100,'.(100 - $strength).',100'],
                    'sepia' => [...$args, '-sepia-tone', $strength.'%'],
                    'warm' => [...$args, '-fill', '#ed9b58', '-colorize', ($strength / 10).'%'],
                    'cool' => [...$args, '-fill', '#6faed9', '-colorize', ($strength / 10).'%'],
                    'sharpen' => [...$args, '-unsharp', '0x'.(0.1 + $strength / 100)],
                };
            }
            if ($restore !== null) {
                $p = $restore['parameters'];
                $args = [...$args, '-brightness-contrast',
                    "{$p['exposure_adjustment']}x0",
                    '-modulate', '100,'.(100 + $p['saturation_recovery']).',100'];
                if ((float) $p['white_balance_shift'] !== 0.0) {
                    $tint = $p['white_balance_shift'] > 0 ? '#ed9b58' : '#6faed9';
                    $args = [...$args, '-fill', $tint,
                        '-colorize', abs((float) $p['white_balance_shift']).'%'];
                }
                if ($p['denoise_strength'] > 0) {
                    $args = [...$args, '-statistic', 'Median', '2x2'];
                }
                if ($p['sharpen_strength'] > 0) {
                    $args = [...$args, '-unsharp', '0x'.(0.1 + $p['sharpen_strength'] / 10)];
                }
            }
            $args = [...$args, '-strip', '-quality', (string) config('media.processing.variant_webp_quality'),
                "webp:{$path}"];
            $this->run($args);
            chmod($path, 0600);
            $sha = hash_file('sha256', $path);
            $size = filesize($path);
            $outputDimensions = getimagesize($path);
            if ($sha === false || $size === false || $outputDimensions === false) {
                throw new MediaProcessingFailed('The edited Photo could not be measured.');
            }

            return new GeneratedMediaVariant($path, 'webp', 'image/webp', $sha,
                $outputDimensions[0], $outputDimensions[1], $size);
        } catch (\Throwable $exception) {
            @unlink($path);
            throw $exception;
        }
    }

    /** @param list<string> $args */
    private function run(array $args): void
    {
        try {
            $process = new Process($args);
            $process->setTimeout((float) config('media.processing.timeout_seconds'));
            $process->mustRun();
        } catch (ProcessTimedOutException) {
            throw new MediaProcessingFailed('Photo rendering timed out.');
        } catch (ProcessFailedException) {
            throw new MediaProcessingFailed('Photo rendering failed.');
        }
    }

    private function binary(): string
    {
        return (string) config('media.validation.imagemagick_binary');
    }
}
