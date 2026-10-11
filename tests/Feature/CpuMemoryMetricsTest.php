<?php

namespace Tests\Feature;

use App\Http\Controllers\CpuMemoryController;
use App\Services\System\CpuInfoService;
use App\Services\System\CpuUsageService;
use App\Services\System\MemoryService;
use App\Services\System\ProcessRankingService;
use App\Services\System\ProcessService;
use Illuminate\Support\Facades\Route;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class CpuMemoryMetricsTest extends TestCase
{
    private const CPU_INFO = [
        'model' => 'Example CPU', 'logical_processors' => 4,
        'uptime_seconds' => 3661.25, 'uptime_formatted' => '0 días, 1 h, 1 min, 1 s',
        'load_average' => ['one_minute' => 1.25, 'five_minutes' => 2.5, 'fifteen_minutes' => 3.75],
    ];

    private const MEMORY_INFO = [
        'memory_total_bytes' => 1048576, 'memory_used_bytes' => 524288,
        'memory_free_bytes' => 262144, 'memory_cached_bytes' => 131072,
        'swap_total_bytes' => 0, 'swap_used_bytes' => 0, 'swap_free_bytes' => 0,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $this->mock(CpuInfoService::class, fn (MockInterface $mock) => $mock->shouldReceive('getInfo')->andReturn(self::CPU_INFO));
        $this->mock(CpuUsageService::class, fn (MockInterface $mock) => $mock->shouldReceive('getUsagePercent')->andReturn(42.5));
        $this->mock(MemoryService::class, fn (MockInterface $mock) => $mock->shouldReceive('getInfo')->andReturn(self::MEMORY_INFO));
        $this->mock(ProcessService::class, fn (MockInterface $mock) => $mock->shouldReceive('getProcesses')->andReturn([]));
    }

    public function test_metrics_route_is_get_and_uses_the_dedicated_controller(): void
    {
        $route = Route::getRoutes()->getByName('cpu-memory.metrics');

        $this->assertNotNull($route);
        $this->assertSame('cpu-memoria/metricas', $route->uri());
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->assertSame(CpuMemoryController::class.'@metrics', $route->getActionName());
        $this->assertSame('/cpu-memoria/metricas', route('cpu-memory.metrics', [], false));
        $this->postJson('/cpu-memoria/metricas')->assertStatus(405);
    }

    public function test_metrics_json_contract_types_and_service_usage(): void
    {
        $this->mock(CpuInfoService::class, fn (MockInterface $mock) => $mock->shouldReceive('getInfo')->once()->andReturn(self::CPU_INFO));
        $this->mock(CpuUsageService::class, fn (MockInterface $mock) => $mock->shouldReceive('getUsagePercent')->once()->andReturn(42.5));
        $this->mock(MemoryService::class, fn (MockInterface $mock) => $mock->shouldReceive('getInfo')->once()->andReturn(self::MEMORY_INFO));
        $processes = [];
        foreach ([3, 1, 6, 2, 5, 4] as $pid) {
            $processes[] = [
                'pid' => $pid, 'cpu_percent' => $pid * 25.0, 'memory_percent' => 7.0 - $pid,
                'command' => '/internal/example --private-argument=private-marker', 'user' => 'private-user',
            ];
        }
        $this->mock(ProcessService::class, fn (MockInterface $mock) => $mock->shouldReceive('getProcesses')->once()->andReturn($processes));
        $this->partialMock(ProcessRankingService::class, fn (MockInterface $mock) => $mock->shouldReceive('getRankings')->once()->with($processes)->passthru());

        $response = $this->getJson('/cpu-memoria/metricas');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/json');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $response->assertExactJson([
            'status' => 'ok',
            'cpu' => ['usage_percent' => 42.5, 'model' => 'Example CPU', 'logical_processors' => 4],
            'uptime' => ['seconds' => 3661.25, 'formatted' => self::CPU_INFO['uptime_formatted']],
            'load_average' => self::CPU_INFO['load_average'],
            'memory' => ['total_bytes' => 1048576, 'used_bytes' => 524288, 'free_bytes' => 262144, 'cached_bytes' => 131072],
            'swap' => ['total_bytes' => 0, 'used_bytes' => 0, 'free_bytes' => 0],
            'top_processes' => [
                'cpu' => array_map(fn (int $pid): array => ['pid' => $pid, 'cpu_percent' => $pid * 25.0], [6, 5, 4, 3, 2]),
                'memory' => array_map(fn (int $pid): array => ['pid' => $pid, 'memory_percent' => 7.0 - $pid], [1, 2, 3, 4, 5]),
            ],
            'timestamp' => now()->utc()->toIso8601String(),
        ]);
        $this->assertIsFloat($response->json('cpu.usage_percent'));
        $this->assertIsString($response->json('cpu.model'));
        $this->assertIsInt($response->json('cpu.logical_processors'));
        $this->assertIsFloat($response->json('uptime.seconds'));
        $this->assertIsString($response->json('uptime.formatted'));
        foreach ($response->json('load_average') as $value) {
            $this->assertIsFloat($value);
        }
        foreach (['memory', 'swap'] as $section) {
            foreach ($response->json($section) as $value) {
                $this->assertIsInt($value);
                $this->assertGreaterThanOrEqual(0, $value);
            }
        }
        foreach (['cpu' => 'cpu_percent', 'memory' => 'memory_percent'] as $section => $metric) {
            $rows = $response->json('top_processes.'.$section);
            $this->assertCount(5, $rows);
            $this->assertTrue(array_is_list($rows));
            foreach ($rows as $row) {
                $this->assertIsInt($row['pid']);
                $this->assertIsFloat($row[$metric]);
            }
        }
        foreach (['command', 'private-marker', 'private-user', '/internal/'] as $privateValue) {
            $response->assertDontSee($privateValue, false);
        }
    }

    public function test_unavailable_readings_preserve_the_json_structure(): void
    {
        $this->mock(CpuInfoService::class, fn (MockInterface $mock) => $mock->shouldReceive('getInfo')->once()->andReturn([
            'model' => null, 'logical_processors' => null, 'uptime_seconds' => null,
            'uptime_formatted' => null, 'load_average' => null,
        ]));
        $this->mock(CpuUsageService::class, fn (MockInterface $mock) => $mock->shouldReceive('getUsagePercent')->once()->andReturn(null));
        $this->mock(MemoryService::class, fn (MockInterface $mock) => $mock->shouldReceive('getInfo')->once()->andReturn(null));

        $this->getJson('/cpu-memoria/metricas')->assertOk()->assertExactJson([
            'status' => 'ok',
            'cpu' => ['usage_percent' => null, 'model' => null, 'logical_processors' => null],
            'uptime' => ['seconds' => null, 'formatted' => null],
            'load_average' => ['one_minute' => null, 'five_minutes' => null, 'fifteen_minutes' => null],
            'memory' => ['total_bytes' => null, 'used_bytes' => null, 'free_bytes' => null, 'cached_bytes' => null],
            'swap' => ['total_bytes' => null, 'used_bytes' => null, 'free_bytes' => null],
            'top_processes' => ['cpu' => [], 'memory' => []],
            'timestamp' => now()->utc()->toIso8601String(),
        ]);
    }

    public function test_zero_cpu_usage_is_a_number_not_null(): void
    {
        $this->mock(CpuUsageService::class, fn (MockInterface $mock) => $mock->shouldReceive('getUsagePercent')->once()->andReturn(0.0));

        $response = $this->getJson('/cpu-memoria/metricas')->assertOk();

        $this->assertSame(0.0, $response->json('cpu.usage_percent'));
    }

    #[DataProvider('failingServices')]
    public function test_exceptions_return_a_controlled_error_even_with_debug_enabled(string $service, string $method): void
    {
        config(['app.debug' => true]);
        $this->mock($service, fn (MockInterface $mock) => $mock->shouldReceive($method)->once()->andThrow(new RuntimeException('/proc/internal private-marker stack trace')));

        $response = $this->getJson('/cpu-memoria/metricas');

        $response->assertStatus(503)->assertExactJson([
            'status' => 'error',
            'error' => ['code' => 'metrics_unavailable', 'message' => 'No se pudieron obtener las métricas del sistema.'],
            'timestamp' => now()->utc()->toIso8601String(),
        ]);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        foreach (['/proc/', 'private-marker', 'stack trace', 'RuntimeException'] as $privateValue) {
            $response->assertDontSee($privateValue, false);
        }
    }

    public static function failingServices(): array
    {
        return [
            'cpu info' => [CpuInfoService::class, 'getInfo'],
            'cpu usage' => [CpuUsageService::class, 'getUsagePercent'],
            'memory' => [MemoryService::class, 'getInfo'],
            'processes' => [ProcessService::class, 'getProcesses'],
            'rankings' => [ProcessRankingService::class, 'getRankings'],
        ];
    }
}
