<?php

namespace Tests\Feature;

use App\Http\Controllers\DeadlockController;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class DeadlockTest extends TestCase
{
    public function test_deadlocks_route_uses_the_dedicated_controller(): void
    {
        $route = Route::getRoutes()->getByName('deadlocks.index');

        $this->assertNotNull($route);
        $this->assertSame('interbloqueos', $route->uri());
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->assertSame(DeadlockController::class.'@index', $route->getActionName());
        $this->assertSame('/interbloqueos', route('deadlocks.index', [], false));
    }

    public function test_initial_page_renders_without_database_queries(): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'role' => User::ROLE_OBSERVER]), 'web');

        DB::shouldReceive('connection')->never();

        $this->get('/interbloqueos')
            ->assertOk()
            ->assertViewIs('deadlocks.index')
            ->assertSee('<html lang="es">', false)
            ->assertSee('<title>Interbloqueos — SysMonitor</title>', false)
            ->assertSeeText('SysMonitor · M4')
            ->assertSee('<h1>Interbloqueos</h1>', false)
            ->assertSeeText('Estructura inicial del módulo de interbloqueos.')
            ->assertSee('<form', false)
            ->assertSee('name="_token"', false)
            ->assertDontSee('<table', false)
            ->assertDontSee('<script', false);
    }

    public function test_module_rejects_missing_scenario(): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'role' => User::ROLE_ADMIN]), 'web');

        $this->post('/interbloqueos')->assertSessionHasErrors('n');
    }
}
