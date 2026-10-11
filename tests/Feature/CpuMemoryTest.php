<?php

namespace Tests\Feature;

use App\Http\Controllers\CpuMemoryController;
use App\Http\Controllers\ProcessController;
use App\Http\Controllers\TestProcessController;
use App\Services\System\CpuInfoService;
use App\Services\System\CpuUsageService;
use App\Services\System\MemoryService;
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
        $this->mock(MemoryService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getInfo')->andReturn(null);
        });
        $this->partialMock(ProcessService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getProcesses')->andReturn([]);
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
            'load_average' => ['one_minute' => 1.25, 'five_minutes' => 2.5, 'fifteen_minutes' => 3.75],
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
        $response->assertSeeTextInOrder(['Carga promedio (1 minuto)', '1.25', 'Carga promedio (5 minutos)', '2.50', 'Carga promedio (15 minutos)', '3.75']);
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
                'load_average' => null,
            ]);
        });

        $response = $this->get('/cpu-memoria');

        $response->assertOk();
        $response->assertViewIs('system.cpu-memory');
        $this->assertSame(15, substr_count($response->getContent(), '<dd>No disponible</dd>'));
    }

    public function test_memory_and_disabled_swap_are_displayed_in_mib(): void
    {
        $info = [
            'memory_total_bytes' => 1048576,
            'memory_used_bytes' => 524288,
            'memory_free_bytes' => 262144,
            'memory_cached_bytes' => 131072,
            'swap_total_bytes' => 0, 'swap_used_bytes' => 0, 'swap_free_bytes' => 0,
        ];
        $this->mock(MemoryService::class, function (MockInterface $mock) use ($info) {
            $mock->shouldReceive('getInfo')->once()->andReturn($info);
        });

        $response = $this->get('/cpu-memoria');

        $response->assertOk();
        $response->assertViewHas('memoryInfo', $info);
        $response->assertSeeTextInOrder([
            'Memoria total', '1.00 MiB', 'Memoria usada', '0.50 MiB',
            'Memoria libre', '0.25 MiB', 'Memoria en caché', '0.13 MiB',
            'Swap total', '0.00 MiB', 'Swap usada', '0.00 MiB', 'Swap libre', '0.00 MiB',
        ]);
    }

    public function test_zero_load_average_is_displayed_as_available(): void
    {
        $this->mock(CpuInfoService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getInfo')->once()->andReturn([
                'model' => null, 'logical_processors' => null,
                'uptime_seconds' => null, 'uptime_formatted' => null,
                'load_average' => ['one_minute' => 0.0, 'five_minutes' => 0.0, 'fifteen_minutes' => 0.0],
            ]);
        });

        $response = $this->get('/cpu-memoria');

        $response->assertOk();
        $this->assertSame(3, substr_count($response->getContent(), '<dd>0.00</dd>'));
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

    public function test_both_rankings_reuse_one_process_read_and_escape_commands(): void
    {
        $processes = [];
        foreach ([3, 1, 6, 2, 5, 4] as $pid) {
            $processes[] = [
                'pid' => $pid, 'command' => 'process '.$pid.' <script>alert(1)</script>',
                'cpu_percent' => $pid * 25.0, 'memory_percent' => 7.0 - $pid,
            ];
        }
        $this->mock(ProcessService::class, function (MockInterface $mock) use ($processes) {
            $mock->shouldReceive('getProcesses')->once()->andReturn($processes);
        });

        $response = $this->get('/cpu-memoria');

        $response->assertOk();
        $response->assertViewHas('topCpuProcesses', fn (array $rows): bool => array_column($rows, 'pid') === [6, 5, 4, 3, 2]);
        $response->assertViewHas('topMemoryProcesses', fn (array $rows): bool => array_column($rows, 'pid') === [1, 2, 3, 4, 5]);
        $response->assertSeeText('Top 5 procesos por CPU');
        $response->assertSeeText('Top 5 procesos por memoria');
        $response->assertDontSee('<script>alert(1)</script>', false);

        foreach (['top-cpu' => [6, 5, 4, 3, 2], 'top-memory' => [1, 2, 3, 4, 5]] as $id => $expectedPids) {
            $this->assertSame(1, preg_match('/<table id="'.$id.'">(.*?)<\/table>/s', $response->getContent(), $matches));
            preg_match_all('/<td class="command">process (\d+) /', $matches[1], $rows);
            $this->assertSame($expectedPids, array_map('intval', $rows[1]));
            $this->assertStringContainsString(e('<script>alert(1)</script>'), $matches[1]);
            $this->assertStringContainsString($id === 'top-cpu' ? '150.0 %' : '6.0 %', $matches[1]);
        }
    }

    public function test_empty_process_list_shows_both_empty_rankings(): void
    {
        $this->mock(ProcessService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getProcesses')->once()->andReturn([]);
        });

        $response = $this->get('/cpu-memoria');

        $response->assertOk();
        $response->assertViewHas('topCpuProcesses', []);
        $response->assertViewHas('topMemoryProcesses', []);
        $this->assertSame(2, substr_count($response->getContent(), 'No hay procesos disponibles para mostrar.'));
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
