<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_the_api_health_endpoint_reports_healthy(): void
    {
        $response = $this->getJson('/api/health');

        $response
            ->assertOk()
            ->assertExactJson([
                'service' => 'api',
                'status' => 'ok',
            ]);
    }
}
