<?php

namespace Tests\Feature;

use App\Http\Controllers\SchedulingController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SchedulingTest extends TestCase
{
    public function test_scheduling_route_uses_the_dedicated_controller(): void
    {
        $route = Route::getRoutes()->getByName('scheduling.index');

        $this->assertNotNull($route);
        $this->assertSame('planificacion', $route->uri());
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->assertSame(SchedulingController::class.'@index', $route->getActionName());
        $this->assertSame('/planificacion', route('scheduling.index', [], false));
    }

    public function test_initial_page_renders_without_database_queries(): void
    {
        DB::shouldReceive('connection')->never();

        $this->get('/planificacion')
            ->assertOk()
            ->assertViewIs('scheduling.index')
            ->assertSee('<html lang="es">', false)
            ->assertSee('<h1>Planificación de CPU</h1>', false)
            ->assertSeeText('Estructura inicial del módulo de planificación de CPU.');
    }
}
