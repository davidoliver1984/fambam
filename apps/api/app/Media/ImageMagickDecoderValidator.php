<?php

namespace App\Media;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class ImageMagickDecoderValidator implements ImageDecoderValidator
{
    public function validate(string $path): DecodedImage
    {
        try {
            $identify = new Process([
                (string) config('media.validation.imagemagick_binary'),
                'identify',
                '-ping',
                '-format',
                '%w %h',
                "{$path}[0]",
            ]);
            $identify->setTimeout((float) config('media.validation.decoder_timeout_seconds'));
            $identify->mustRun();
        } catch (ProcessTimedOutException) {
            throw new MediaValidationFailed('decoder_timeout');
        } catch (ProcessFailedException) {
            throw new MediaValidationFailed('invalid_image');
        }

        if (preg_match('/^(\d+) (\d+)$/', trim($identify->getOutput()), $matches) !== 1) {
            throw new MediaValidationFailed('invalid_dimensions');
        }
        $width = (int) $matches[1];
        $height = (int) $matches[2];
        $maxPixels = (int) config('media.validation.max_pixels');
        if ($width < 1 || $height < 1 || $width > $maxPixels || $height > intdiv($maxPixels, $width)) {
            throw new MediaValidationFailed('pixel_limit_exceeded');
        }

        try {
            $decode = new Process([
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
                "{$path}[0]",
                'null:',
            ]);
            $decode->setTimeout((float) config('media.validation.decoder_timeout_seconds'));
            $decode->mustRun();
        } catch (ProcessTimedOutException) {
            throw new MediaValidationFailed('decoder_timeout');
        } catch (ProcessFailedException) {
            throw new MediaValidationFailed('invalid_image');
        }

        return new DecodedImage($width, $height);
    }
}
