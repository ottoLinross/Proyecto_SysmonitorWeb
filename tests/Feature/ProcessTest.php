<?php

namespace Tests\Feature;

use Tests\TestCase;

class ProcessTest extends TestCase
{
    public function test_processes_page_returns_a_successful_response(): void
    {
        $response = $this->get('/procesos');

        $response->assertStatus(200);
        $response->assertSeeText('Módulo de Procesos');
    }
}
