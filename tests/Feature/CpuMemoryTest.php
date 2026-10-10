<?php

namespace Tests\Feature;

use App\Http\Controllers\CpuMemoryController;
use App\Http\Controllers\ProcessController;
use App\Http\Controllers\TestProcessController;
use App\Services\System\CpuInfoService;
use App\Services\System\CpuUsageService;
use App\Services\System\ProcessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Mockery\MockInterface;
use Tests\TestCase;

class CpuMemoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(CpuUsageService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getUsagePercent')->andReturn(42.5);
        });
    }

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
        $info = [
            'model' => 'Example CPU <script>alert(1)</script>',
            'logical_processors' => 4,
            'uptime_seconds' => 90061.25,
            'uptime_formatted' => '1 día, 1 h, 1 min, 1 s',
        ];
        $this->mock(CpuInfoService::class, function (MockInterface $mock) use ($info) {
            $mock->shouldReceive('getInfo')->once()->andReturn($info);
        });

        $response = $this->get('/cpu-memoria');

        $response->assertOk();
        $response->assertViewIs('system.cpu-memory');
        $response->assertSeeText('CPU y Memoria');
        $response->assertViewHas('cpuInfo', $info);
        $response->assertViewHas('cpuUsagePercent', 42.5);
        $response->assertSeeText('42.5 %');
        $response->assertSee(e($info['model']), false);
        $response->assertDontSee($info['model'], false);
        $response->assertSee('<dd>4</dd>', false);
        $response->assertSeeText('90061.25');
        $response->assertSeeText($info['uptime_formatted']);
        $response->assertSee('href="'.route('processes.index').'"', false);
    }

    public function test_cpu_memory_page_handles_unavailable_information(): void
    {
        $this->mock(CpuUsageService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getUsagePercent')->once()->andReturn(null);
        });
        $this->mock(CpuInfoService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getInfo')->once()->andReturn([
                'model' => null, 'logical_processors' => null,
                'uptime_seconds' => null, 'uptime_formatted' => null,
            ]);
        });

        $response = $this->get('/cpu-memoria');

        $response->assertOk();
        $response->assertViewIs('system.cpu-memory');
        $this->assertSame(5, substr_count($response->getContent(), '<dd>No disponible</dd>'));
    }

    public function test_cpu_memory_page_displays_zero_usage_as_available(): void
    {
        $this->mock(CpuUsageService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getUsagePercent')->once()->andReturn(0.0);
        });

        $response = $this->get('/cpu-memoria');

        $response->assertOk();
        $response->assertViewHas('cpuUsagePercent', 0.0);
        $response->assertSeeText('0.0 %');
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
