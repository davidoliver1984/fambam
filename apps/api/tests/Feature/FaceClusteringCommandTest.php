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
}
