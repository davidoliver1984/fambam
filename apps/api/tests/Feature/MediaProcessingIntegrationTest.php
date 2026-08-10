<?php

namespace Tests\Feature;

use App\Media\ExifToolMediaMetadataExtractor;
use App\Media\ImageMagickCanonicalImageGenerator;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MediaProcessingIntegrationTest extends TestCase
{
    public function test_real_processing_applies_orientation_extracts_private_metadata_and_strips_the_canonical(): void
    {
        $this->requireIntegrationEnvironment();
        $source = $this->temporaryPath('jpg');
        $canonicalPaths = [];

        try {
            $this->runProcess(['magick', '-size', '2x3', 'gradient:red-blue', "jpeg:{$source}"]);
            $this->runProcess([
                'exiftool',
                '-overwrite_original',
                '-Orientation#=6',
                '-Make=Archive Camera Co',
                '-Model=Scanner 1987',
                '-DateTimeOriginal=1987:06:01 12:30:00',
                '-OffsetTimeOriginal=+01:00',
                '-GPSLatitude=51.5014',
                '-GPSLatitudeRef=N',
                '-GPSLongitude=0.1419',
                '-GPSLongitudeRef=W',
                $source,
            ]);
            $originalChecksum = hash_file('sha256', $source);
            $this->assertIsString($originalChecksum);

            $extractor = new ExifToolMediaMetadataExtractor;
            $metadata = $extractor->extract($source);
            $this->assertSame(2, $metadata->width);
            $this->assertSame(3, $metadata->height);
            $this->assertSame(6, $metadata->orientation);
            $this->assertSame('Archive Camera Co', $metadata->cameraMake);
            $this->assertSame('Scanner 1987', $metadata->cameraModel);
            $this->assertSame('1987:06:01 12:30:00+01:00', $metadata->captureTimestamp);
            $this->assertSame('51.5014000', $metadata->gpsLatitude);
            $this->assertSame('-0.1419000', $metadata->gpsLongitude);
            $this->assertNotNull($metadata->rawExif);

            $canonical = (new ImageMagickCanonicalImageGenerator)->generate($source);
            $canonicalPaths[] = $canonical->path;
            $this->assertSame('jpg', $canonical->extension);
            $this->assertSame('image/jpeg', $canonical->mimeType);
            $this->assertSame('3 2 sRGB', trim($this->runProcess([
                'magick',
                'identify',
                '-format',
                '%w %h %[colorspace]',
                $canonical->path,
            ])));

            $canonicalMetadata = $extractor->extract($canonical->path);
            $this->assertNull($canonicalMetadata->orientation);
            $this->assertNull($canonicalMetadata->cameraMake);
            $this->assertNull($canonicalMetadata->gpsLatitude);
            $this->assertNull($canonicalMetadata->gpsLongitude);
            $this->assertNull($canonicalMetadata->rawExif);
            $this->assertSame($originalChecksum, hash_file('sha256', $source));

            $regenerated = (new ImageMagickCanonicalImageGenerator)->generate($source);
            $canonicalPaths[] = $regenerated->path;
            $this->assertSame($canonical->sha256, $regenerated->sha256);
        } finally {
            @unlink($source);
            foreach ($canonicalPaths as $canonicalPath) {
                @unlink($canonicalPath);
            }
        }
    }

    public function test_real_processing_preserves_meaningful_alpha_and_supports_heic_heif_and_tiff(): void
    {
        $this->requireIntegrationEnvironment();
        $sources = [];
        $canonicals = [];

        try {
            $alpha = $this->temporaryPath('png');
            $sources[] = $alpha;
            $this->runProcess(['magick', '-size', '2x2', 'xc:none', '-fill', 'red', '-draw', 'point 0,0', "png:{$alpha}"]);
            $transparentCanonical = (new ImageMagickCanonicalImageGenerator)->generate($alpha);
            $canonicals[] = $transparentCanonical->path;
            $this->assertSame('png', $transparentCanonical->extension);
            $this->assertSame('False', trim($this->runProcess([
                'magick',
                'identify',
                '-format',
                '%[opaque]',
                $transparentCanonical->path,
            ])));

            foreach (['heic', 'heif', 'tif'] as $extension) {
                $source = $this->temporaryPath($extension);
                $sources[] = $source;
                $this->runProcess(['magick', '-size', '2x3', 'gradient:red-blue', $source]);
                $canonical = (new ImageMagickCanonicalImageGenerator)->generate($source);
                $canonicals[] = $canonical->path;
                $this->assertSame('jpg', $canonical->extension);
                $this->assertSame('2 3', trim($this->runProcess([
                    'magick',
                    'identify',
                    '-format',
                    '%w %h',
                    $canonical->path,
                ])));
            }
        } finally {
            foreach ([...$sources, ...$canonicals] as $path) {
                @unlink($path);
            }
        }
    }

    private function requireIntegrationEnvironment(): void
    {
        if (! config('media.processing.integration_test_enabled')) {
            $this->markTestSkipped('Real ImageMagick and ExifTool processing runs in the media-processing smoke gate.');
        }
    }

    private function temporaryPath(string $extension): string
    {
        return sys_get_temp_dir().'/fambam-processing-'.bin2hex(random_bytes(12)).'.'.$extension;
    }

    /** @param list<string> $command */
    private function runProcess(array $command): string
    {
        $process = new Process($command);
        $process->setTimeout(30);
        $process->mustRun();

        return $process->getOutput();
    }
}
