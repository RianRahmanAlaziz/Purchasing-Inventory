<?php

namespace Tests\Feature\Api\V1;

use Tests\TestCase;

final class HealthApiTest extends TestCase
{
    public function test_health_endpoint_returns_success(): void
    {
        $this->getJson('/api/v1/health')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath(
                'message',
                'API is running.'
            )
            ->assertJsonPath(
                'data.service',
                'purchasing-inventory'
            )
            ->assertJsonPath('data.version', 'v1')
            ->assertJsonPath('data.status', 'ok');
    }

    public function test_health_endpoint_rejects_post(): void
    {
        $this->postJson('/api/v1/health')
            ->assertStatus(405);
    }
}
