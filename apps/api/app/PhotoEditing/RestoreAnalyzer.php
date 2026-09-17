<?php

namespace App\PhotoEditing;

use App\Media\MediaProcessingFailed;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class RestoreAnalyzer
{
    /** @return array<string, mixed>|null */
    public function analyze(string $canonicalPath): ?array
    {
        try {
            $process = new Process([(string) config('media.validation.imagemagick_binary'),
                "{$canonicalPath}[0]", '-resize', '256x256>', '-format',
                '%[fx:mean.r] %[fx:mean.g] %[fx:mean.b] %[fx:standard_deviation]', 'info:']);
            $process->setTimeout((float) config('media.processing.timeout_seconds'));
            $process->mustRun();
        } catch (ProcessTimedOutException) {
            throw new MediaProcessingFailed('Restore analysis timed out.');
        } catch (ProcessFailedException) {
            throw new MediaProcessingFailed('Restore analysis failed.');
        }
        $values = preg_split('/\s+/', trim($process->getOutput()));
        if ($values === false || count($values) !== 4 || count(array_filter($values, 'is_numeric')) !== 4) {
            throw new MediaProcessingFailed('Restore analysis returned invalid measurements.');
        }
        [$r, $g, $b, $deviation] = array_map('floatval', $values);
        $mean = ($r + $g + $b) / 3;
        $cast = $b - $r;
        $exposure = $mean < 0.32 ? min(12, round((0.42 - $mean) * 40, 2)) : 0.0;
        $contrast = $deviation < 0.14 ? min(10, round((0.18 - $deviation) * 70, 2)) : 0.0;
        $saturation = $deviation < 0.12 ? min(8, round((0.15 - $deviation) * 50, 2)) : 0.0;
        $whiteBalance = abs($cast) > 0.08 ? max(-5, min(5, round($cast * 20, 2))) : 0.0;
        if (max($exposure, $contrast, $saturation, abs($whiteBalance)) < 1.0) {
            return null;
        }

        return ['schema_version' => 1, 'algorithm_version' => 'restore-v1',
            'processing_mode' => 'conservative',
            'parameters' => ['white_balance_shift' => $whiteBalance,
                'exposure_adjustment' => $exposure,
                'saturation_recovery' => $saturation,
                'denoise_strength' => 0.0, 'sharpen_strength' => $contrast],
            'outcome' => 'applied'];
    }
}
