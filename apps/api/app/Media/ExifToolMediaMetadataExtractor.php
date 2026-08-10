<?php

namespace App\Media;

use JsonException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class ExifToolMediaMetadataExtractor implements MediaMetadataExtractor
{
    public function extract(string $path): ExtractedMediaMetadata
    {
        $metadata = $this->metadata($path);

        return new ExtractedMediaMetadata(
            $this->positiveInteger($metadata['ImageWidth'] ?? null, 'width'),
            $this->positiveInteger($metadata['ImageHeight'] ?? null, 'height'),
            $this->orientation($metadata['Orientation'] ?? null),
            $this->text($metadata['Make'] ?? null, 255),
            $this->text($metadata['Model'] ?? null, 255),
            $this->captureTimestamp($metadata),
            $this->coordinate($metadata['GPSLatitude'] ?? null, -90, 90),
            $this->coordinate($metadata['GPSLongitude'] ?? null, -180, 180),
            $this->profile($path, 'EXIF'),
            $this->profile($path, 'ICC_Profile'),
        );
    }

    /** @return array<string, mixed> */
    private function metadata(string $path): array
    {
        $output = $this->run([
            (string) config('media.processing.exiftool_binary'),
            '-json',
            '-n',
            '-ImageWidth',
            '-ImageHeight',
            '-Orientation#',
            '-Make',
            '-Model',
            '-DateTimeOriginal',
            '-OffsetTimeOriginal',
            '-GPSLatitude',
            '-GPSLongitude',
            $path,
        ]);

        try {
            $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new MediaProcessingFailed('Metadata extraction returned invalid JSON.');
        }

        if (! is_array($decoded) || ! is_array($decoded[0] ?? null)) {
            throw new MediaProcessingFailed('Metadata extraction returned no image record.');
        }

        return $decoded[0];
    }

    private function profile(string $path, string $name): ?string
    {
        $profile = $this->run([
            (string) config('media.processing.exiftool_binary'),
            "-{$name}",
            '-b',
            $path,
        ]);

        if ($profile === '') {
            return null;
        }
        if (strlen($profile) > (int) config('media.processing.metadata_profile_max_bytes')) {
            throw new MediaProcessingFailed('An embedded metadata profile exceeds the configured limit.');
        }

        return $profile;
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
            throw new MediaProcessingFailed('Metadata extraction timed out.');
        } catch (ProcessFailedException) {
            throw new MediaProcessingFailed('Metadata extraction failed.');
        }
    }

    private function positiveInteger(mixed $value, string $field): int
    {
        if (! is_int($value) && ! is_float($value) && ! is_numeric($value)) {
            throw new MediaProcessingFailed("Image {$field} metadata is missing.");
        }
        $integer = (int) $value;
        if ($integer < 1) {
            throw new MediaProcessingFailed("Image {$field} metadata is invalid.");
        }

        return $integer;
    }

    private function orientation(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        $orientation = (int) $value;

        return $orientation >= 1 && $orientation <= 8 ? $orientation : null;
    }

    private function text(mixed $value, int $maxLength): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $maxLength);
    }

    /** @param array<string, mixed> $metadata */
    private function captureTimestamp(array $metadata): ?string
    {
        $timestamp = $this->text($metadata['DateTimeOriginal'] ?? null, 32);
        if ($timestamp === null) {
            return null;
        }
        $offset = $this->text($metadata['OffsetTimeOriginal'] ?? null, 7);

        return mb_substr($timestamp.($offset === null ? '' : $offset), 0, 40);
    }

    private function coordinate(mixed $value, float $minimum, float $maximum): ?string
    {
        if (! is_int($value) && ! is_float($value) && ! is_numeric($value)) {
            return null;
        }
        $coordinate = (float) $value;
        if (! is_finite($coordinate) || $coordinate < $minimum || $coordinate > $maximum) {
            return null;
        }

        return number_format($coordinate, 7, '.', '');
    }
}
