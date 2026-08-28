<?php

namespace App\FaceRecognition;

use RuntimeException;

final class FaceRecognitionCalibration
{
    public function assertAccepted(): void
    {
        $profile = config('face_recognition.calibration_profile');
        $profiles = config('face_recognition.profiles');
        if (! is_string($profile) || $profile === ''
            || ! is_array($profiles) || ! array_key_exists($profile, $profiles)) {
            throw new RuntimeException('The configured face-recognition calibration profile is not accepted.');
        }
    }
}
