<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * Test that the API health endpoint returns a successful response.
     */
    public function test_the_api_returns_a_successful_response(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertStatus(200);
    }
}
