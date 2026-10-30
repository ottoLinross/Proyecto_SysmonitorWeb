<?php

namespace Tests\Feature\M6;

use App\Http\Controllers\StorageController;
use App\Http\Middleware\AuthenticateStorage;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Models\User;
use App\Services\System\ProcessPriorityService;
use App\Services\System\ProcessSignalService;
use App\Services\System\TestProcessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'm6_authorization_testing',
            'database.connections.m6_authorization_testing' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'session.driver' => 'array',
            'cache.default' => 'array',
        ]);
        $this->assertTrue($this->app->environment('testing'));
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());

        foreach (['0001_01_01_000000_create_users_table.php', '2026_10_24_000000_add_role_to_users_table.php'] as $file) {
            $migration = require database_path('migrations/'.$file);
            $migration->up();
        }
    }

    protected function tearDown(): void
    {
        DB::purge('m6_authorization_testing');

        parent::tearDown();
    }

    public function test_guests_cannot_read_modules_and_get_destinations_are_preserved(): void
    {
        foreach (self::readRoutes() as $path) {
            $this->get($path)->assertRedirect(route('login'))
                ->assertSessionHas('url.intended', url($path));
            $this->getJson($path)->assertUnauthorized();
        }
    }

    public function test_guests_cannot_execute_any_functional_post(): void
    {
        $this->forbidSystemOperations();

        foreach (self::writeRoutes() as $name => $path) {
            $this->post($path, ['role' => User::ROLE_ADMIN])->assertRedirect(route('login'));
            $this->postJson($path, ['role' => User::ROLE_ADMIN])->assertUnauthorized();
            $this->assertContains(EnsureUserIsAdmin::class, Route::getRoutes()->getByName($name)->gatherMiddleware());
        }
    }

    #[DataProvider('nonAdminRoles')]
    public function test_non_admins_are_denied_before_controllers_bindings_or_system_operations(mixed $role): void
    {
        $user = $this->user();
        // Simulate a corrupt persisted role without using the validated assignment API.
        DB::table('users')->where('id', $user->id)->update(['role' => $role]);
        $this->actingAs($user->fresh(), 'web');
        $this->withSession(['role' => User::ROLE_ADMIN]);
        $this->forbidSystemOperations();

        DB::connection()->enableQueryLog();
        foreach (self::writeRoutes() as $path) {
            $this->post($path, ['role' => User::ROLE_ADMIN])->assertForbidden();
            $this->postJson($path, ['role' => User::ROLE_ADMIN])->assertForbidden();
        }
        // No action or model binding should even query the database after authorization fails.
        $this->assertSame([], DB::connection()->getQueryLog());
        $this->assertSame(1, User::query()->count());
    }

    public static function nonAdminRoles(): array
    {
        return [[User::ROLE_OBSERVER], ['unknown'], ['ADMIN'], [''], ['1']];
    }

    public function test_admin_reaches_every_administrative_action_without_real_side_effects(): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN), 'web');
        $this->forbidSystemOperations();

        foreach (self::writeRoutes() as $name => $path) {
            $executed = false;
            // Replace only the final action in memory; retain the real route middleware stack.
            Route::getRoutes()->getByName($name)->uses(function () use (&$executed) {
                $executed = true;

                return response()->noContent();
            });
            $this->postJson($path, ['role' => User::ROLE_OBSERVER])->assertNoContent();
            $this->assertTrue($executed, $name);
        }
    }

    public function test_rejected_requests_never_reach_the_final_action(): void
    {
        $this->actingAs($this->user(), 'web');

        foreach (self::writeRoutes() as $name => $path) {
            $executed = false;
            Route::getRoutes()->getByName($name)->uses(function () use (&$executed) {
                $executed = true;

                return response()->noContent();
            });
            $this->postJson($path, ['role' => User::ROLE_ADMIN])->assertForbidden();
            $this->assertFalse($executed, $name);
        }
    }

    #[DataProvider('validRoles')]
    public function test_both_roles_can_read_all_module_routes(string $role): void
    {
        $this->actingAs($this->user($role), 'web');
        $basicPassword = bin2hex(random_bytes(16));
        config([
            'storage_access.username' => 'm6-test',
            'storage_access.password_hash' => password_hash($basicPassword, PASSWORD_BCRYPT, ['cost' => 4]),
        ]);
        $this->withBasicAuth('m6-test', $basicPassword);

        foreach (self::readRoutes() as $path) {
            $route = Route::getRoutes()->match(Request::create($path));
            $executed = false;
            $route->uses(function () use (&$executed) {
                $executed = true;

                return response('authorized read');
            });
            $this->get($path)->assertOk()->assertSee('authorized read');
            $this->assertTrue($executed, $path);
        }
    }

    public static function validRoles(): array
    {
        return [[User::ROLE_ADMIN], [User::ROLE_OBSERVER]];
    }

    public function test_storage_basic_remains_required_after_laravel_login(): void
    {
        $this->actingAs($this->user(), 'web');
        config(['storage_access.username' => null, 'storage_access.password_hash' => null]);
        $this->mock(StorageController::class)->shouldNotReceive('index');
        $this->get('/almacenamiento')->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Basic realm="SysMonitor M5", charset="UTF-8"');
        $this->assertContains(AuthenticateStorage::class, Route::getRoutes()->getByName('storage.index')->gatherMiddleware());
    }

    public function test_signed_framework_file_routes_require_login_even_with_a_valid_signature(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('m6-test.txt', 'test file');
        $read = URL::temporarySignedRoute('storage.local', now()->addMinute(), ['path' => 'm6-test.txt'], absolute: false);
        $write = URL::temporarySignedRoute('storage.local.upload', now()->addMinute(), ['path' => 'm6-new.txt', 'upload' => true], absolute: false);

        $this->get($read)->assertRedirect(route('login'));
        $this->getJson($read)->assertUnauthorized();
        $this->put($write, ['role' => 'admin'])->assertRedirect(route('login'));
        $this->putJson($write, ['role' => 'admin'])->assertUnauthorized();
        Storage::disk('local')->assertMissing('m6-new.txt');
    }

    #[DataProvider('validRoles')]
    public function test_authenticated_framework_reads_still_require_valid_signatures(string $role): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('m6-test.txt', 'test file');
        $this->actingAs($this->user($role), 'web');
        $url = URL::temporarySignedRoute('storage.local', now()->addMinute(), ['path' => 'm6-test.txt'], absolute: false);

        $this->get('/storage/m6-test.txt')->assertForbidden();
        $this->get($url)->assertOk()->assertStreamedContent('test file');
    }

    public function test_framework_upload_rejects_observer_and_unknown_role_even_with_valid_signature(): void
    {
        Storage::fake('local');
        $user = $this->user();
        $url = URL::temporarySignedRoute('storage.local.upload', now()->addMinute(), ['path' => 'm6-new.txt', 'upload' => true], absolute: false);

        foreach ([User::ROLE_OBSERVER, 'unknown'] as $role) {
            DB::table('users')->where('id', $user->id)->update(['role' => $role]);
            $this->actingAs($user->fresh(), 'web');
            $this->putJson($url, ['role' => User::ROLE_ADMIN])->assertForbidden();
            Storage::disk('local')->assertMissing('m6-new.txt');
        }
    }

    public function test_admin_framework_upload_keeps_signature_checks_and_writes_only_fake_storage(): void
    {
        Storage::fake('local');
        $this->actingAs($this->user(User::ROLE_ADMIN), 'web');
        $this->put('/storage/m6-new.txt?upload=true')->assertForbidden();
        Storage::disk('local')->assertMissing('m6-new.txt');
        $url = URL::temporarySignedRoute('storage.local.upload', now()->addMinute(), ['path' => 'm6-new.txt', 'upload' => true], absolute: false);
        $this->call('PUT', $url, [], [], [], ['CONTENT_TYPE' => 'text/plain'], 'test upload')->assertNoContent();
        $this->assertSame('test upload', Storage::disk('local')->get('m6-new.txt'));
    }

    public function test_all_registered_routes_have_the_expected_security_boundary(): void
    {
        $this->assertCount(28, Route::getRoutes());
        foreach (Route::getRoutes() as $route) {
            $middleware = $route->gatherMiddleware();
            if (in_array($route->uri(), ['login', 'logout', 'up'], true)) {
                $this->assertNotContains('auth:web', $middleware);

                continue;
            }
            $this->assertContains('auth:web', $middleware, $route->uri());
            $this->assertContains('web', $middleware, $route->uri());
            if (array_intersect(['POST', 'PUT', 'PATCH', 'DELETE'], $route->methods())) {
                $this->assertContains(EnsureUserIsAdmin::class, $middleware, $route->uri());
            } else {
                $this->assertNotContains(EnsureUserIsAdmin::class, $middleware, $route->uri());
            }
        }
    }

    public function test_health_is_public_and_does_not_include_internal_application_data(): void
    {
        $this->get('/up')->assertOk()->assertDontSee('APP_KEY')
            ->assertDontSee(base_path())->assertDontSee('DB_DATABASE')->assertDontSee('internal@example.test');
        $this->get('/login')->assertOk();
        $this->post('/logout')->assertRedirect(route('login'));
    }

    public function test_csrf_blocks_authenticated_writes_and_framework_uploads(): void
    {
        Storage::fake('local');
        $this->actingAs($this->user(User::ROLE_ADMIN), 'web');
        $this->forbidSystemOperations();
        $this->app->instance('env', 'production');

        foreach (self::writeRoutes() as $path) {
            $this->post($path)->assertStatus(419);
        }
        $url = URL::temporarySignedRoute('storage.local.upload', now()->addMinute(), ['path' => 'm6-new.txt', 'upload' => true], absolute: false);
        $this->put($url)->assertStatus(419);
        $this->post('/logout')->assertStatus(419);
        Storage::disk('local')->assertMissing('m6-new.txt');
    }

    private function user(string $role = User::ROLE_OBSERVER): User
    {
        $user = new User;
        $user->setRawAttributes([
            'name' => 'Authorization Test', 'email' => 'authorization@example.test',
            'password' => '', 'role' => $role,
        ]);
        $user->save();

        return $user;
    }

    private function forbidSystemOperations(): void
    {
        $this->mock(TestProcessService::class)->shouldNotReceive('launch');
        $this->mock(ProcessSignalService::class)->shouldNotReceive('send');
        $this->mock(ProcessPriorityService::class)->shouldNotReceive('change');
    }

    private static function readRoutes(): array
    {
        return [
            '/', '/procesos', '/cpu-memoria', '/cpu-memoria/metricas', '/planificacion',
            '/planificacion/historial', '/planificacion/historial/1', '/interbloqueos',
            '/interbloqueos/historial', '/interbloqueos/historial/1', '/almacenamiento',
        ];
    }

    private static function writeRoutes(): array
    {
        return [
            'processes.test.store' => '/procesos/prueba',
            'processes.test.signal' => '/procesos/prueba/999/signal',
            'processes.test.priority' => '/procesos/prueba/999/priority',
            'scheduling.manual' => '/planificacion/manual',
            'scheduling.random' => '/planificacion/aleatoria',
            'scheduling.simulate' => '/planificacion/simular',
            'scheduling.compare' => '/planificacion/comparar',
            'deadlocks.validate' => '/interbloqueos',
            'deadlocks.request' => '/interbloqueos/solicitudes',
            'deadlocks.recovery' => '/interbloqueos/recuperacion',
            'deadlocks.history.store' => '/interbloqueos/historial',
        ];
    }
}
