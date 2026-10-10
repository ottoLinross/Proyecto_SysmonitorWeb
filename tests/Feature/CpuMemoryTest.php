<?php

namespace Tests\Feature;

use App\Http\Controllers\CpuMemoryController;
use App\Http\Controllers\ProcessController;
use App\Http\Controllers\TestProcessController;
use App\Services\System\ProcessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Mockery\MockInterface;
use Tests\TestCase;

class CpuMemoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_cpu_memory_route_uses_the_dedicated_controller(): void
    {
        $route = Route::getRoutes()->getByName('cpu-memory.index');

        $this->assertNotNull($route);
        $this->assertSame('cpu-memoria', $route->uri());
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->assertSame(CpuMemoryController::class.'@index', $route->getActionName());
        $this->assertSame('/cpu-memoria', route('cpu-memory.index', [], false));
    }

    public function test_cpu_memory_page_renders_the_initial_view(): void
    {
        $response = $this->get('/cpu-memoria');

        $response->assertOk();
        $response->assertViewIs('system.cpu-memory');
        $response->assertSeeText('CPU y Memoria');
        $response->assertSeeText('Las métricas de CPU y memoria aún no están disponibles.');
        $response->assertSee('href="'.route('processes.index').'"', false);
    }

    public function test_m1_processes_page_still_renders(): void
    {
        $this->partialMock(ProcessService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getProcesses')->once()->andReturn([]);
        });

        $response = $this->get('/procesos');

        $response->assertOk();
        $response->assertViewIs('processes.index');
        $response->assertSeeText('Módulo de Procesos');
    }

    public function test_m1_routes_keep_their_names_methods_and_controllers(): void
    {
        $expectedRoutes = [
            'processes.index' => ['procesos', ['GET', 'HEAD'], ProcessController::class.'@index'],
            'processes.test.store' => ['procesos/prueba', ['POST'], TestProcessController::class.'@store'],
            'processes.test.signal' => ['procesos/prueba/{managedProcess}/signal', ['POST'], TestProcessController::class.'@signal'],
            'processes.test.priority' => ['procesos/prueba/{managedProcess}/priority', ['POST'], TestProcessController::class.'@priority'],
        ];

        foreach ($expectedRoutes as $name => [$uri, $methods, $action]) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, $name);
            $this->assertSame($uri, $route->uri());
            $this->assertSame($methods, $route->methods());
            $this->assertSame($action, $route->getActionName());
        }
    }
}
