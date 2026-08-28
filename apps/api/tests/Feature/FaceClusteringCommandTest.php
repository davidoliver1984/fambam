<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FaceClusteringCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_operational_command_refuses_to_run_before_calibration_gate_opens(): void
    {
        config()->set('face_recognition.processing_enabled', false);

        $this->artisan('fambam:rebuild-face-clusters', [
            '--family-space' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            '--actor' => '1',
        ])->expectsOutputToContain('remains disabled')->assertFailed();

        $this->assertDatabaseCount('face_cluster_generations', 0);
    }

    public function test_operational_command_fails_closed_for_an_unaccepted_profile(): void
    {
        config()->set([
            'face_recognition.processing_enabled' => true,
            'face_recognition.calibration_profile' => 'not-accepted',
        ]);

        $this->artisan('fambam:rebuild-face-clusters', [
            '--family-space' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            '--actor' => '1',
        ])->expectsOutputToContain('not accepted')->assertFailed();

        $this->assertDatabaseCount('face_cluster_generations', 0);
    }

    public function test_accepted_profile_exposes_only_its_calibrated_values(): void
    {
        $this->assertSame('buffalo-l-v0.7-private-family-v1', config('face_recognition.calibration_profile'));
        $this->assertSame(0.350, config('face_recognition.clustering_max_cosine_distance'));
        $this->assertSame(0.350, config('face_recognition.suggestion_strong_max_distance'));
        $this->assertSame(0.685, config('face_recognition.suggestion_shortlist_max_distance'));
        $this->assertSame(0.300, config('face_recognition.suggestion_ambiguity_margin'));
        $this->assertSame(2, config('face_recognition.suggestion_minimum_strong_references'));
    }
}
