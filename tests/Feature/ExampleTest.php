<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'role' => User::ROLE_OBSERVER]), 'web');

        $response = $this->get('/');

        $response->assertStatus(200);
    }
}
