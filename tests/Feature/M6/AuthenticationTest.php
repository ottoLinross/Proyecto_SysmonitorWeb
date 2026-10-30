<?php

namespace Tests\Feature\M6;

use App\Http\Middleware\AuthenticateStorage;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    private string $password;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'm6_auth_testing',
            'database.connections.m6_auth_testing' => [
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

        $this->password = bin2hex(random_bytes(24));
    }

    protected function tearDown(): void
    {
        DB::purge('m6_auth_testing');

        parent::tearDown();
    }

    public function test_login_form_has_csrf_and_no_registration_or_example_credentials(): void
    {
        $this->get('/login')->assertOk()->assertSeeText('SysMonitor Web')
            ->assertSee('name="email"', false)->assertSee('type="password"', false)
            ->assertSee('name="_token"', false)->assertDontSee('/register')
            ->assertDontSee('example.test');
    }

    public function test_valid_user_logs_in_and_session_id_and_csrf_token_rotate(): void
    {
        $user = $this->user();
        $this->get('/login');
        $id = session()->getId();
        $token = session()->token();

        $this->post('/login', $this->credentials())->assertRedirect(route('processes.index'));

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNotSame($id, session()->getId());
        $this->assertNotSame($token, session()->token());
    }

    public function test_wrong_password_and_missing_account_have_identical_errors_and_never_flash_password(): void
    {
        $this->user();
        $errors = [];
        foreach (['internal@example.test', 'missing@example.test'] as $email) {
            $response = $this->from('/login')->post('/login', [
                'email' => $email, 'password' => 'incorrect-test-value',
            ]);
            $response->assertRedirect('/login')->assertSessionHasErrors('email');
            $errors[] = session('errors')->get('email');
            $this->assertArrayNotHasKey('password', session()->getOldInput());
            $this->assertGuest('web');
        }
        $this->assertSame($errors[0], $errors[1]);
        $this->get('/login')->assertSeeText('Las credenciales proporcionadas no son correctas.')
            ->assertDontSee('incorrect-test-value');
    }

    public function test_email_and_password_are_validated(): void
    {
        $this->postJson('/login', [])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
        $this->postJson('/login', ['email' => 'invalid', 'password' => []])
            ->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
        $this->assertGuest('web');
    }

    public function test_authenticated_visitors_redirect_without_reauthenticating(): void
    {
        $user = $this->user();
        $this->actingAs($user, 'web')->get('/login')->assertRedirect(route('processes.index'));
        $this->post('/login', ['role' => 'admin'])->assertRedirect(route('processes.index'));
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_post_logout_clears_authentication_session_data_and_rotates_csrf(): void
    {
        $user = $this->user();
        $this->post('/login', $this->credentials())->assertRedirect();
        $this->withSession(['private_marker' => 'session-only']);
        $id = session()->getId();
        $token = session()->token();

        $this->post('/logout')->assertRedirect(route('login'))->assertSessionMissing('private_marker');

        $this->assertGuest('web');
        $this->assertNotSame($id, session()->getId());
        $this->assertNotSame($token, session()->token());
        $this->assertFalse(session()->has(Auth::guard('web')->getName()));
        $this->assertSame(User::ROLE_OBSERVER, $user->fresh()->role);
    }

    public function test_logout_is_post_only_and_guests_can_logout_safely(): void
    {
        $this->get('/logout')->assertStatus(405);
        $this->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest('web');
    }

    public function test_registration_and_password_recovery_are_not_available(): void
    {
        foreach (['/register', '/forgot-password', '/reset-password'] as $path) {
            $this->get($path)->assertNotFound();
            $this->post($path)->assertNotFound();
        }
        $this->assertFalse(Route::has('register'));
        $this->assertSame(0, User::query()->count());
    }

    public function test_login_throttles_normalized_email_and_ip_and_expires(): void
    {
        $this->user();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/login', [
                'email' => $i % 2 ? 'INTERNAL@example.test' : 'internal@example.test',
                'password' => 'incorrect-test-value',
            ])->assertUnprocessable();
        }
        $this->postJson('/login', $this->credentials())->assertStatus(429);
        $this->assertGuest('web');

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.2'])
            ->postJson('/login', ['email' => 'internal@example.test', 'password' => 'incorrect-test-value'])
            ->assertUnprocessable();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
        $this->postJson('/login', ['email' => 'another@example.test', 'password' => 'incorrect-test-value'])
            ->assertUnprocessable();

        $this->travel(61)->seconds();
        $this->post('/login', $this->credentials())->assertRedirect(route('processes.index'));
        $this->assertAuthenticated('web');
    }

    public function test_successful_login_clears_failed_attempts(): void
    {
        $this->user();
        $this->postJson('/login', ['email' => 'internal@example.test', 'password' => 'incorrect-test-value'])
            ->assertUnprocessable();
        $key = 'm6-login:'.hash('sha256', 'internal@example.test|127.0.0.1');
        $this->assertSame(1, RateLimiter::attempts($key));
        $this->post('/login', $this->credentials())->assertRedirect();
        $this->assertSame(0, RateLimiter::attempts($key));
    }

    #[DataProvider('roles')]
    public function test_login_and_logout_ignore_role_injection(string $role): void
    {
        $user = $this->user($role);
        $otherRole = $role === User::ROLE_ADMIN ? User::ROLE_OBSERVER : User::ROLE_ADMIN;
        $this->post('/login', [...$this->credentials(), 'role' => $otherRole])
            ->assertRedirect(route('processes.index'));
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertSame($role, $user->fresh()->role);
        $this->post('/logout', ['role' => $otherRole])->assertRedirect(route('login'));
        $this->assertSame($role, $user->fresh()->role);
        $this->assertSame(1, User::query()->count());
    }

    public static function roles(): array
    {
        return [[User::ROLE_ADMIN], [User::ROLE_OBSERVER]];
    }

    #[DataProvider('intendedDestinations')]
    public function test_intended_redirects_only_use_safe_registered_get_routes(string $intended, string $expected): void
    {
        $this->user();
        $this->withSession(['url.intended' => $intended])
            ->post('/login', $this->credentials())->assertRedirect($expected)
            ->assertSessionMissing('url.intended');
    }

    public static function intendedDestinations(): array
    {
        return [
            ['/cpu-memoria', '/cpu-memoria'],
            ['http://localhost/planificacion?pagina=2', '/planificacion?pagina=2'],
            ['/planificacion/historial/1', '/planificacion/historial/1'],
            ['https://outside.example/planificacion', '/procesos'],
            ['//outside.example/planificacion', '/procesos'],
            ['http://localhost.outside.example/procesos', '/procesos'],
            ['javascript:alert(1)', '/procesos'],
            ['/nonexistent', '/procesos'],
            ['/login', '/procesos'],
            ['/logout', '/procesos'],
            ['/procesos/prueba', '/procesos'],
            ['/%5coutside.example', '/procesos'],
            ["/cpu-memoria\r\nLocation: https://outside.example", '/procesos'],
        ];
    }

    public function test_original_routes_remain_registered_and_storage_basic_is_unchanged(): void
    {
        $expected = [
            'processes.index' => ['GET', 'procesos'],
            'storage.index' => ['GET', 'almacenamiento'],
            'deadlocks.index' => ['GET', 'interbloqueos'],
            'deadlocks.validate' => ['POST', 'interbloqueos'],
            'deadlocks.request' => ['POST', 'interbloqueos/solicitudes'],
            'deadlocks.recovery' => ['POST', 'interbloqueos/recuperacion'],
            'deadlocks.history.store' => ['POST', 'interbloqueos/historial'],
            'deadlocks.history.index' => ['GET', 'interbloqueos/historial'],
            'deadlocks.history.show' => ['GET', 'interbloqueos/historial/{id}'],
            'scheduling.index' => ['GET', 'planificacion'],
            'scheduling.manual' => ['POST', 'planificacion/manual'],
            'scheduling.random' => ['POST', 'planificacion/aleatoria'],
            'scheduling.simulate' => ['POST', 'planificacion/simular'],
            'scheduling.compare' => ['POST', 'planificacion/comparar'],
            'scheduling.history' => ['GET', 'planificacion/historial'],
            'scheduling.history.show' => ['GET', 'planificacion/historial/{id}'],
            'cpu-memory.index' => ['GET', 'cpu-memoria'],
            'cpu-memory.metrics' => ['GET', 'cpu-memoria/metricas'],
            'processes.test.store' => ['POST', 'procesos/prueba'],
            'processes.test.signal' => ['POST', 'procesos/prueba/{managedProcess}/signal'],
            'processes.test.priority' => ['POST', 'procesos/prueba/{managedProcess}/priority'],
        ];
        foreach ($expected as $name => [$method, $uri]) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route);
            $this->assertSame($uri, $route->uri());
            $this->assertContains($method, $route->methods());
            $this->assertSame(
                array_merge(
                    ['web', 'auth:web'],
                    $name === 'storage.index' ? [AuthenticateStorage::class] : [],
                    $method === 'POST' ? [EnsureUserIsAdmin::class] : [],
                ),
                $route->gatherMiddleware(),
            );
        }
        foreach (['/', 'up', 'storage/{path}'] as $uri) {
            $this->assertContains($uri, array_map(fn ($route) => $route->uri(), Route::getRoutes()->getRoutes()));
        }
        $this->assertCount(28, Route::getRoutes());
    }

    public function test_login_and_logout_require_csrf_outside_test_bypass(): void
    {
        // Keep the dedicated in-memory database while enabling the real CSRF check.
        $this->app->instance('env', 'production');
        $this->post('/login', $this->credentials())->assertStatus(419);
        $this->post('/logout')->assertStatus(419);
        $this->assertSame(0, User::query()->count());
    }

    private function user(string $role = User::ROLE_OBSERVER): User
    {
        $user = new User;
        $user->name = 'Internal Test';
        $user->email = 'internal@example.test';
        $user->password = Hash::make($this->password);
        $user->role = $role;
        $user->save();

        return $user;
    }

    private function credentials(): array
    {
        return ['email' => 'internal@example.test', 'password' => $this->password];
    }
}
