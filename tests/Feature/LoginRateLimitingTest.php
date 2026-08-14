<?php

namespace Tests\Feature;

use Tests\TestCase;

class LoginRateLimitingTest extends TestCase
{
    public function test_api_login_endpoint_is_rate_limited()
    {
        // Make 10 requests to /api/login
        for ($i = 0; $i < 10; $i++) {
            $response = $this->postJson('/api/login', [
                'email' => 'test@example.com',
                'password' => 'wrong-password',
            ]);

            // Should be 401 Unauthorized or other validation error, not 429
            $this->assertNotEquals(429, $response->getStatusCode());
        }

        // The 11th request should be rate limited (429)
        $response = $this->postJson('/api/login', [
            'email' => 'test@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(429);
    }

    public function test_web_login_endpoint_is_rate_limited()
    {
        // Make 10 requests to /login
        for ($i = 0; $i < 10; $i++) {
            $response = $this->post('/login', [
                'email' => 'test@example.com',
                'password' => 'wrong-password',
            ]);

            // Should be 302 redirect back or other status, not 429
            $this->assertNotEquals(429, $response->getStatusCode());
        }

        // The 11th request should be rate limited (429)
        $response = $this->post('/login', [
            'email' => 'test@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(429);
    }
}
