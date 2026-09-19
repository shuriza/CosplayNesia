<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RuntimeInfrastructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_liveness_and_readiness_are_distinct_and_correlated(): void
    {
        Cache::setDefaultDriver('database');

        $liveness = $this->getJson('/up');
        $liveness->assertOk()->assertJsonPath('status', 'up');

        $readiness = $this->getJson('/ready');
        $readiness->assertOk()->assertExactJson(['status' => 'ready']);
        $this->assertNotEmpty($readiness->headers->get('X-Request-ID'));
    }

    public function test_valid_client_request_id_is_echoed_and_invalid_value_is_replaced(): void
    {
        $requestId = '5b1b9496-718a-4d35-a44e-4db749dd8807';
        $this->getJson('/api/products', ['X-Request-ID' => $requestId])
            ->assertOk()
            ->assertHeader('X-Request-ID', $requestId);

        $response = $this->getJson('/api/products', ['X-Request-ID' => "invalid\r\nvalue"])->assertOk();
        $this->assertNotSame("invalid\r\nvalue", $response->headers->get('X-Request-ID'));
    }
}
