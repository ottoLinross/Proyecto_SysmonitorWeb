<?php

namespace Tests\Feature\M6;

use App\Http\Middleware\AuthenticateStorage;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Models\User;
use App\Services\Storage\StorageOverviewService;
use App\Services\System\CpuInfoService;
use App\Services\System\CpuUsageService;
use App\Services\System\MemoryService;
use App\Services\System\ProcessService;
use App\Services\System\TestProcessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Mockery\MockInterface;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_the_dashboard_to_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_admin_can_view_dashboard_with_the_unified_layout_and_five_module_links(): void
    {
        $this->mockReadSources();
        $response = $this->actingAs($this->user(User::ROLE_ADMIN), 'web')->get('/');

        $response->assertOk()
            ->assertViewIs('welcome')
            ->assertSee('<div class="sysmonitor-app">', false)
            ->assertSeeText('Indicadores principales')
            ->assertSeeText('Administrador');

        $this->assertModuleLinks($response->getContent());
    }

    public function test_observer_can_read_the_dashboard_without_administrative_controls(): void
    {
        $this->mockReadSources();
        $response = $this->actingAs($this->user(User::ROLE_OBSERVER), 'web')->get('/');

        $response->assertOk()->assertSeeText('Observador')
            ->assertDontSeeText('Lanzar proceso de prueba')
            ->assertDontSeeText('Cambiar prioridad')->assertDontSeeText('Enviar señal');
        $response->assertDontSee('action="'.route('processes.test.store').'"', false);
    }

    public function test_dashboard_renders_simulated_metrics_with_units_and_calculated_percentages(): void
    {
        $this->mockReadSources(
            cpuInfo: [
                'model' => 'CPU de prueba', 'logical_processors' => 4,
                'uptime_formatted' => '2 días, 1 h, 2 min, 3 s',
                'load_average' => ['one_minute' => 0.25, 'five_minutes' => 0.5, 'fifteen_minutes' => 0.75],
            ],
            cpuUsage: 23.45,
            memory: ['memory_used_bytes' => 536870912, 'memory_total_bytes' => 1073741824],
            processes: [['pid' => 1], ['pid' => 2], ['pid' => 3]],
        );

        $response = $this->actingAs($this->user(User::ROLE_OBSERVER), 'web')->get('/');

        $response->assertOk()->assertSeeText('23.5%')->assertSeeText('CPU de prueba')
            ->assertSeeText('4 procesadores lógicos')->assertSeeText('2 días, 1 h, 2 min, 3 s')
            ->assertSeeText('0.25 / 0.50 / 0.75')->assertSeeText('50.0%')
            ->assertSeeText('0.50 GiB utilizados de 1.00 GiB')->assertSeeText('3');
    }

    public function test_zero_values_remain_zero_and_do_not_become_unavailable(): void
    {
        $this->mockReadSources(cpuUsage: 0.0, memory: [
            'memory_used_bytes' => 0,
            'memory_total_bytes' => 1073741824,
        ], processes: []);

        $response = $this->actingAs($this->user(User::ROLE_ADMIN), 'web')->get('/');

        $response->assertOk()->assertSeeText('0.0%')->assertSeeText('0.00 GiB utilizados de 1.00 GiB')
            ->assertSeeText('Procesos detectados');

        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $processCount = $xpath->query('//*[@id="dashboard-processes-title"]')->item(0);
        $this->assertSame('0', trim($processCount->textContent));
        $this->assertSame('0.0%', trim($xpath->query('//*[@id="dashboard-cpu-title"]')->item(0)->textContent));
        $this->assertSame('0.0%', trim($xpath->query('//*[@id="dashboard-memory-title"]')->item(0)->textContent));
    }

    public function test_missing_values_and_zero_memory_total_are_marked_unavailable_without_division_by_zero(): void
    {
        $this->mockReadSources(cpuUsage: null, memory: ['memory_used_bytes' => 0, 'memory_total_bytes' => 0]);

        $response = $this->actingAs($this->user(User::ROLE_OBSERVER), 'web')->get('/');

        $response->assertOk()->assertSeeText('Dato no disponible')->assertDontSeeText('INF')
            ->assertDontSeeText('NAN');
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame('Dato no disponible', trim($xpath->query('//*[@id="dashboard-cpu-title"]')->item(0)->textContent));
        $this->assertSame('Dato no disponible', trim($xpath->query('//*[@id="dashboard-memory-title"]')->item(0)->textContent));
    }

    public function test_a_failed_read_source_does_not_hide_other_metrics_or_expose_exception_details(): void
    {
        $this->mockReadSources(
            cpuInfo: new \RuntimeException('private source path /srv/private'),
            cpuUsage: 12.0,
            memory: new \RuntimeException('secret memory failure'),
            processes: [['pid' => 8]],
        );

        $response = $this->actingAs($this->user(User::ROLE_ADMIN), 'web')->get('/');

        $response->assertOk()->assertSeeText('12.0%')->assertSeeText('8')
            ->assertSeeText('Dato no disponible')->assertDontSeeText('/srv/private')
            ->assertDontSeeText('secret memory failure');
    }

    public function test_dashboard_dynamic_text_is_escaped_and_storage_basic_service_is_not_used(): void
    {
        $this->mockReadSources(cpuInfo: [
            'model' => '<script>alert("dashboard-xss")</script>',
            'logical_processors' => null,
            'uptime_formatted' => null,
            'load_average' => null,
        ]);

        $response = $this->actingAs($this->user(User::ROLE_OBSERVER), 'web')->get('/');

        $response->assertOk()
            ->assertSee('&lt;script&gt;alert(&quot;dashboard-xss&quot;)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert("dashboard-xss")</script>', false)
            ->assertSeeText('La consulta M5 conserva su autenticación HTTP Basic independiente.');
        $this->assertFalse(app()->resolved(StorageOverviewService::class));
    }

    public function test_unknown_role_gets_read_only_dashboard_but_not_admin_backend_access(): void
    {
        $this->mockReadSources();
        $this->mock(TestProcessService::class)->shouldNotReceive('launch');
        $user = $this->user(User::ROLE_OBSERVER);
        $user->setRawAttributes(array_replace($user->getAttributes(), ['role' => 'unexpected-role']));

        $response = $this->actingAs($user, 'web')->get('/');

        $response->assertOk()->assertSeeText('Rol no reconocido')
            ->assertDontSeeText('Administrador')->assertDontSeeText('Observador');
        $this->post(route('processes.test.store'))->assertForbidden();
    }

    public function test_root_route_and_existing_security_middleware_remain_unchanged(): void
    {
        $root = Route::getRoutes()->match(Request::create('/'));
        $storage = Route::getRoutes()->getByName('storage.index');
        $adminAction = Route::getRoutes()->getByName('processes.test.store');

        $this->assertSame('/', $root->uri());
        $this->assertContains('GET', $root->methods());
        $this->assertContains('auth:web', $root->gatherMiddleware());
        $this->assertContains(AuthenticateStorage::class, $storage->gatherMiddleware());
        $this->assertContains(EnsureUserIsAdmin::class, $adminAction->gatherMiddleware());
        $this->assertCount(28, Route::getRoutes()->getRoutes());
    }

    private function mockReadSources(
        mixed $cpuInfo = [
            'model' => 'CPU simulada', 'logical_processors' => 2,
            'uptime_formatted' => '1 día, 0 h, 0 min, 0 s',
            'load_average' => ['one_minute' => 0.1, 'five_minutes' => 0.2, 'fifteen_minutes' => 0.3],
        ],
        mixed $cpuUsage = 24.0,
        mixed $memory = ['memory_used_bytes' => 536870912, 'memory_total_bytes' => 1073741824],
        mixed $processes = [['pid' => 1], ['pid' => 2]],
    ): void {
        $this->mock(CpuInfoService::class, function (MockInterface $mock) use ($cpuInfo): void {
            $expectation = $mock->shouldReceive('getInfo')->once();
            $cpuInfo instanceof \Throwable ? $expectation->andThrow($cpuInfo) : $expectation->andReturn($cpuInfo);
        });
        $this->mock(CpuUsageService::class, function (MockInterface $mock) use ($cpuUsage): void {
            $expectation = $mock->shouldReceive('getUsagePercent')->once();
            $cpuUsage instanceof \Throwable ? $expectation->andThrow($cpuUsage) : $expectation->andReturn($cpuUsage);
        });
        $this->mock(MemoryService::class, function (MockInterface $mock) use ($memory): void {
            $expectation = $mock->shouldReceive('getInfo')->once();
            $memory instanceof \Throwable ? $expectation->andThrow($memory) : $expectation->andReturn($memory);
        });
        $this->mock(ProcessService::class, function (MockInterface $mock) use ($processes): void {
            $expectation = $mock->shouldReceive('getProcesses')->once();
            $processes instanceof \Throwable ? $expectation->andThrow($processes) : $expectation->andReturn($processes);
        });
    }

    private function assertModuleLinks(string $html): void
    {
        foreach (['processes.index', 'cpu-memory.index', 'scheduling.index', 'deadlocks.index', 'storage.index'] as $routeName) {
            $this->assertStringContainsString('href="'.route($routeName).'"', $html);
        }
    }

    private function user(string $role): User
    {
        $user = new User;
        $user->name = 'Dashboard Test User';
        $user->email = uniqid('dashboard-test-', true).'@example.test';
        $user->setRawAttributes(array_replace($user->getAttributes(), ['password' => '']));
        $user->role = $role;
        $user->save();

        return $user;
    }
}
