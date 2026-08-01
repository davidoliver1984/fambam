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
            ->assertHeader('X-Request-ID')
            ->assertHeader('X-Correlation-ID')
            ->assertExactJson([
                'service' => 'api',
                'status' => 'ok',
            ]);
    }

    public function test_the_api_preserves_request_context_headers(): void
    {
        $response = $this->withHeaders([
            'X-Request-ID' => 'request-123',
            'X-Correlation-ID' => 'family-456',
        ])->getJson('/api/health');

        $response
            ->assertHeader('X-Request-ID', 'request-123')
            ->assertHeader('X-Correlation-ID', 'family-456');
    }

    public function test_synthetic_observability_route_is_unavailable_outside_local_and_testing(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');

        try {
            $this->postJson('/api/observability/synthetic-upload')->assertNotFound();
        } finally {
            $this->app->detectEnvironment(static fn (): string => 'testing');
        }
    }
}
