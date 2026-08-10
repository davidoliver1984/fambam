<?php

namespace App\Media;

class MediaFormatDetector
{
    public function detect(string $path): ?DetectedMediaFormat
    {
        $bytes = file_get_contents($path, false, null, 0, 32);
        if ($bytes === false) {
            return null;
        }

        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return DetectedMediaFormat::Jpeg;
        }
        if (str_starts_with($bytes, "\x89PNG\r\n\x1A\n")) {
            return DetectedMediaFormat::Png;
        }
        if (strlen($bytes) >= 12 && substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP') {
            return DetectedMediaFormat::Webp;
        }
        if (str_starts_with($bytes, "II\x2A\x00") || str_starts_with($bytes, "MM\x00\x2A")) {
            return DetectedMediaFormat::Tiff;
        }

        return $this->detectIsoBaseMediaFormat($bytes);
    }

    private function detectIsoBaseMediaFormat(string $bytes): ?DetectedMediaFormat
    {
        if (strlen($bytes) < 12 || substr($bytes, 4, 4) !== 'ftyp') {
            return null;
        }

        $brands = [];
        for ($offset = 8; $offset + 4 <= strlen($bytes); $offset += 4) {
            $brands[] = substr($bytes, $offset, 4);
        }

        if (array_intersect($brands, ['avif', 'avis']) !== []) {
            return null;
        }

        foreach ($brands as $brand) {
            if (in_array($brand, ['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis'], true)) {
                return DetectedMediaFormat::Heic;
            }
        }
        foreach ($brands as $brand) {
            if (in_array($brand, ['mif1', 'msf1'], true)) {
                return DetectedMediaFormat::Heif;
            }
        }

        return null;
    }
}
