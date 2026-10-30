<?php

namespace Tests\Feature\M6;

use App\Http\Middleware\AuthenticateStorage;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Models\User;
use App\Services\Deadlock\BankerSafetyAnalyzer;
use App\Services\Scheduling\SchedulingSimulation;
use App\Services\Storage\ActiveSessionsService;
use App\Services\Storage\FileExplorerService;
use App\Services\Storage\FilesystemUsageService;
use App\Services\Storage\MountService;
use App\Services\Storage\StorageOverviewService;
use App\Services\Storage\StorageSummaryService;
use App\Services\Storage\SystemUsersService;
use App\Services\System\CpuInfoService;
use App\Services\System\CpuUsageService;
use App\Services\System\MemoryService;
use App\Services\System\ProcessRankingService;
use App\Services\System\ProcessService;
use App\Services\System\TestProcessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Mockery\MockInterface;
use Tests\TestCase;

class ModuleLayoutIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['storage_access.username' => 'storage-test', 'storage_access.password_hash' => password_hash('test-only-password', PASSWORD_BCRYPT, ['cost' => 4])]);
    }

    public function test_module_pages_use_shared_layout_and_mark_the_active_module(): void
    {
        $this->mockProcessReads();
        $this->mockCpuMemoryReads();
        $this->actingAs($this->user(User::ROLE_OBSERVER), 'web');

        $pages = [
            ['processes.index', '/procesos', 'Procesos', 200],
            ['cpu-memory.index', '/cpu-memoria', 'CPU y Memoria', 200],
            ['scheduling.index', '/planificacion', 'Planificación', 200],
            ['scheduling.history', '/planificacion/historial', 'Planificación', 200],
            ['deadlocks.index', '/interbloqueos', 'Interbloqueos', 200],
            ['deadlocks.history.index', '/interbloqueos/historial', 'Interbloqueos', 200],
            ['deadlocks.history.show', '/interbloqueos/historial/999', 'Interbloqueos', 404],
        ];

        foreach ($pages as [$routeName, $path, $moduleLabel, $status]) {
            $response = $this->get($path)->assertStatus($status);
            $html = $response->getContent();
            $response->assertSee('<div class="sysmonitor-app">', false)
                ->assertSee('aria-label="Navegación principal"', false)
                ->assertSeeText('Observador');
            $this->assertModuleLinks($html);
            $this->assertSingleMainAndActiveModule($html, $routeName, $moduleLabel);
        }

    }

    public function test_admin_and_observer_keep_read_access_and_guests_remain_blocked(): void
    {
        $this->mockProcessReads();
        $this->mockCpuMemoryReads();
        $this->actingAs($this->user(User::ROLE_ADMIN), 'web');
        $this->get('/procesos')->assertOk()->assertSeeText('Administrador');
        $this->get('/cpu-memoria')->assertOk()->assertSeeText('Administrador');
        $this->get('/planificacion')->assertOk()->assertSeeText('Administrador');
        $this->get('/interbloqueos')->assertOk()->assertSeeText('Administrador');

        auth('web')->logout();
        foreach (['/procesos', '/cpu-memoria', '/planificacion', '/interbloqueos', '/almacenamiento'] as $path) {
            $this->get($path)->assertRedirect(route('login'));
        }
    }

    public function test_storage_basic_remains_independent_and_invalid_credentials_are_rejected(): void
    {
        $this->actingAs($this->user(User::ROLE_OBSERVER), 'web');
        $this->mockStorageReads();

        $this->get('/almacenamiento')->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Basic realm="SysMonitor M5", charset="UTF-8"');
        $response = $this->withBasicAuth('storage-test', 'test-only-password')->get('/almacenamiento')->assertOk();
        $response->assertDontSee('test-only-password')->assertDontSee('storage-test')
            ->assertSee('<div class="sysmonitor-app">', false)
            ->assertSee('aria-label="Navegación principal"', false)
            ->assertSeeText('Observador')
            ->assertSee('<main id="storage-module">', false)
            ->assertSee('aria-current="page"', false)
            ->assertDontSee('<form', false);
        $this->assertModuleLinks($response->getContent());
        $this->assertSingleMainAndActiveModule($response->getContent(), 'storage.index', 'Almacenamiento');
    }

    public function test_storage_layout_preserves_identity_read_access_and_no_admin_controls_for_both_roles(): void
    {
        $this->mockStorageReads();

        foreach ([User::ROLE_ADMIN => 'Administrador', User::ROLE_OBSERVER => 'Observador'] as $role => $label) {
            $user = $this->user($role);
            $this->actingAs($user, 'web');

            $response = $this->withBasicAuth('storage-test', 'test-only-password')->get('/almacenamiento')->assertOk();
            $response->assertSeeText($user->name)
                ->assertSeeText($label)
                ->assertDontSee('<form', false)
                ->assertDontSee('<button', false)
                ->assertDontSee('<input', false)
                ->assertDontSee('test-only-password')
                ->assertDontSee('storage-test');

            auth('web')->logout();
        }
    }

    public function test_layout_logout_remains_post_with_csrf_and_module_forms_keep_their_routes(): void
    {
        $this->mockProcessReads(3);
        $this->mockCpuMemoryReads();
        $this->actingAs($this->user(User::ROLE_ADMIN), 'web');

        $processes = $this->get('/procesos')->assertOk();
        $processes->assertSee('action="'.route('logout').'"', false)
            ->assertSee('method="POST"', false)->assertSee('name="_token"', false)
            ->assertSee('action="'.route('processes.test.store').'"', false)
            ->assertSee('action="'.route('processes.index').'"', false);

        $dashboard = $this->get('/')->assertOk();
        $dashboard->assertSee('action="'.route('logout').'"', false)
            ->assertSee('method="POST"', false)->assertSee('name="_token"', false);

        $cpuMemory = $this->get('/cpu-memoria')->assertOk();
        $cpuMemory->assertSee('action="'.route('logout').'"', false)
            ->assertSee('method="POST"', false)->assertSee('name="_token"', false);

        $scheduling = $this->get('/planificacion')->assertOk();
        $scheduling->assertSee('method="POST" action="'.route('scheduling.manual').'"', false)
            ->assertSee('method="POST" action="'.route('scheduling.random').'"', false)
            ->assertSee('name="_token"', false);

        $deadlocks = $this->get('/interbloqueos')->assertOk();
        $deadlocks->assertSee('method="POST" action="'.route('deadlocks.validate').'"', false)
            ->assertSee('name="_token"', false);
    }

    public function test_observer_post_operations_remain_forbidden_without_running_actions(): void
    {
        $this->mock(TestProcessService::class)->shouldNotReceive('launch');
        $this->mock(SchedulingSimulation::class)->shouldNotReceive('run');
        $this->mock(BankerSafetyAnalyzer::class)->shouldNotReceive('analyze');
        $this->actingAs($this->user(User::ROLE_OBSERVER), 'web');

        $this->post(route('processes.test.store'))->assertForbidden();
        $this->post(route('scheduling.manual'), ['processes' => []])->assertForbidden();
        $this->post(route('deadlocks.validate'), [])->assertForbidden();
    }

    public function test_graphs_and_required_scripts_keep_their_dom_hooks_and_load_once(): void
    {
        $this->mockProcessReads();
        $this->mockCpuMemoryReads();
        $this->actingAs($this->user(User::ROLE_ADMIN), 'web');

        $processes = $this->get('/procesos')->assertOk();
        $processes->assertSee('id="process-tree-title"', false)
            ->assertSee('id="managed-process-table"', false);

        $cpuMemory = $this->get('/cpu-memoria')->assertOk();
        $script = '<script src="'.asset('js/cpu-memory.js').'" defer></script>';
        $cpuMemory->assertSee('id="cpu-chart-plot"', false)->assertSee('id="memory-chart-plot"', false)
            ->assertSee($script, false);
        $this->assertSame(1, substr_count($cpuMemory->getContent(), $script));

        $scheduling = $this->get('/planificacion')->assertOk();
        $scheduling->assertSee('id="process-inputs"', false)->assertSee('id="add-process"', false);
        $this->assertSame(1, substr_count($scheduling->getContent(), "const body = document.getElementById('process-inputs')"));
        $simulation = $this->post(route('scheduling.simulate'), [
            'processes' => [['id' => 'P1', 'arrival_time' => 0, 'burst_time' => 2, 'priority' => 1]],
            'algorithm' => 'fcfs',
        ])->assertOk();
        $simulation->assertSee('id="gantt-segments"', false);

        $deadlocks = $this->post(route('deadlocks.validate'), [
            'n' => '2', 'm' => '2', 'allocation' => '[[1,0],[0,1]]',
            'max' => '[[2,1],[1,2]]', 'available' => '[1,1]',
        ])->assertOk();
        $deadlocks->assertSee('id="graph-nodes-caption"', false)->assertSee('name="allocation"', false);
    }

    public function test_existing_route_methods_and_security_middleware_are_preserved(): void
    {
        foreach (['processes.index', 'cpu-memory.index', 'scheduling.index', 'deadlocks.index', 'storage.index'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route);
            $this->assertContains('GET', $route->methods());
        }
        $this->assertContains(AuthenticateStorage::class, Route::getRoutes()->getByName('storage.index')->gatherMiddleware());
        $this->assertContains(EnsureUserIsAdmin::class, Route::getRoutes()->getByName('processes.test.store')->gatherMiddleware());
        $this->assertCount(28, Route::getRoutes()->getRoutes());
    }

    private function mockProcessReads(int $calls = 2): void
    {
        $this->partialMock(ProcessService::class, function (MockInterface $mock) use ($calls): void {
            $mock->shouldReceive('getProcesses')->times($calls)->andReturn([]);
        });
    }

    private function mockCpuMemoryReads(): void
    {
        $this->mock(CpuInfoService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getInfo')->andReturn([
                'model' => 'CPU de prueba', 'logical_processors' => 2,
                'uptime_seconds' => 60.0, 'uptime_formatted' => '0 días, 0 h, 1 min, 0 s',
                'load_average' => ['one_minute' => 0.1, 'five_minutes' => 0.2, 'fifteen_minutes' => 0.3],
            ]);
        });
        $this->mock(CpuUsageService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getUsagePercent')->andReturn(0.0);
        });
        $this->mock(MemoryService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getInfo')->andReturn([
                'memory_total_bytes' => 1024, 'memory_used_bytes' => 512, 'memory_free_bytes' => 512,
                'memory_cached_bytes' => 0, 'swap_total_bytes' => 0, 'swap_used_bytes' => 0, 'swap_free_bytes' => 0,
            ]);
        });
        $this->mock(ProcessRankingService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getRankings')->andReturn(['cpu' => [], 'memory' => []]);
        });
    }

    private function mockStorageReads(): void
    {
        $sections = [
            'devices' => 'Dispositivos de almacenamiento', 'usage' => 'Uso del espacio',
            'mounts' => 'Puntos de montaje', 'users' => 'Usuarios del sistema',
            'sessions' => 'Sesiones activas', 'explorer' => 'Explorador de archivos',
        ];
        $this->mock(StorageOverviewService::class, function (MockInterface $mock) use ($sections): void {
            $mock->shouldReceive('sections')->andReturn($sections);
            $mock->shouldReceive('devices')->andReturn(['available' => false, 'status' => 'No disponible', 'devices' => []]);
        });
        $this->mock(FilesystemUsageService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('usage')->andReturn(['available' => false, 'status' => 'No disponible', 'filesystems' => []]);
        });
        $this->mock(MountService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('active')->andReturn(['available' => false, 'status' => 'No disponible', 'mounts' => []]);
            $mock->shouldReceive('configured')->andReturn(['available' => false, 'status' => 'No disponible', 'mounts' => []]);
        });
        $this->mock(SystemUsersService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('users')->andReturn(['available' => false, 'status' => 'No disponible', 'users' => [], 'uid_min' => 1000, 'fallback' => true]);
        });
        $this->mock(ActiveSessionsService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sessions')->andReturn(['available' => false, 'status' => 'No disponible', 'sessions' => []]);
        });
        $this->mock(FileExplorerService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('browse')->andReturn(['available' => false, 'path' => '', 'parent' => null, 'entries' => [], 'message' => 'No disponible']);
        });
        $this->mock(StorageSummaryService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('summarize')->andReturn([
                'disks' => null, 'partitions' => null, 'mounts' => null, 'users' => null, 'sessions' => null,
                'sources' => array_fill_keys(['devices', 'usage', 'mounts', 'configured', 'users', 'sessions', 'explorer'], 'No disponible'),
            ]);
        });
    }

    private function assertModuleLinks(string $html): void
    {
        $this->assertStringContainsString('href="'.url('/').'"', $html);
        foreach (['processes.index', 'cpu-memory.index', 'scheduling.index', 'deadlocks.index', 'storage.index'] as $routeName) {
            $this->assertStringContainsString('href="'.route($routeName).'"', $html);
        }
    }

    private function assertSingleMainAndActiveModule(string $html, string $routeName, string $label): void
    {
        $document = new \DOMDocument;
        @$document->loadHTML($html);
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//main[@id="main-content"]')->length);
        $this->assertSame(2, $xpath->query('//a[@aria-current="page"]')->length);
        $this->assertSame(2, $xpath->query('//a[@aria-current="page"]//span[text()="'.$label.'"]')->length);
        $this->assertNotNull(Route::getRoutes()->getByName($routeName));
    }

    private function user(string $role): User
    {
        $user = new User;
        $user->name = 'Module Integration User';
        $user->email = uniqid('module-integration-', true).'@example.test';
        $user->setRawAttributes(array_replace($user->getAttributes(), ['password' => '']));
        $user->role = $role;
        $user->save();

        return $user;
    }
}
