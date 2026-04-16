<?php

namespace Tests\Feature\Api\V1;

use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_health_check_returns_success(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'API is healthy',
            ])
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [
                    'version',
                    'laravel',
                    'php',
                    'timestamp',
                ],
            ]);
    }

    public function test_health_check_returns_correct_data_types(): void
    {
        $response = $this->getJson('/api/health');

        $data = $response->json('data');

        $this->assertIsString($data['version']);
        $this->assertIsString($data['laravel']);
        $this->assertIsString($data['php']);
        $this->assertIsString($data['timestamp']);
    }
}
