<?php

namespace Tests\Feature\M6;

use App\Console\Commands\CreateInitialAdmin;
use App\Http\Controllers\StorageController;
use App\Http\Middleware\AuthenticateStorage;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\M6\AuditLogger;
use App\Services\System\TestProcessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Mockery\MockInterface;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    private string $password;

    protected function setUp(): void
    {
        parent::setUp();
        $this->password = 'Hardening-'.bin2hex(random_bytes(16)).'Aa1!';
    }

    public function test_session_lifecycle_rotates_on_login_and_is_invalidated_on_logout(): void
    {
        $user = $this->user(User::ROLE_ADMIN);
        $this->get('/login');
        $sessionId = session()->getId();
        $csrfToken = session()->token();

        $this->post('/login', ['email' => $user->email, 'password' => $this->password])
            ->assertRedirect(route('processes.index'));
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNotSame($sessionId, session()->getId());
        $this->assertNotSame($csrfToken, session()->token());

        $this->withSession(['private-marker' => 'session data']);
        $sessionId = session()->getId();
        $csrfToken = session()->token();
        $this->post('/logout')->assertRedirect(route('login'));

        $this->assertGuest('web');
        $this->assertNotSame($sessionId, session()->getId());
        $this->assertNotSame($csrfToken, session()->token());
        $this->assertFalse(session()->has(Auth::guard('web')->getName()));
        $this->assertFalse(session()->has('private-marker'));
    }

    public function test_login_throttle_is_bounded_and_public_account_flows_are_absent(): void
    {
        $user = $this->user();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/login', ['email' => $user->email, 'password' => 'incorrect-password'])
                ->assertUnprocessable()->assertJsonValidationErrors('email');
        }
        $this->postJson('/login', ['email' => $user->email, 'password' => $this->password])
            ->assertStatus(429);

        foreach (['/register', '/forgot-password', '/reset-password'] as $path) {
            $this->get($path)->assertNotFound();
            $this->post($path)->assertNotFound();
        }
        $this->assertFalse(Route::has('register'));
        $this->assertGuest('web');
    }

    public function test_guests_cannot_access_private_data_and_roles_have_only_their_existing_access(): void
    {
        $this->get('/')->assertRedirect(route('login'));
        $this->get('/usuarios')->assertRedirect(route('login'));

        $observer = $this->user(User::ROLE_OBSERVER);
        $this->actingAs($observer, 'web')->get('/cpu-memoria')->assertOk();
        $this->post('/planificacion/simular', [])->assertForbidden();
        $this->get('/usuarios')->assertForbidden();

        Auth::forgetGuards();
        $admin = $this->user(User::ROLE_ADMIN);
        $this->actingAs($admin, 'web')->get('/usuarios')->assertOk();
        $this->assertSame(2, User::query()->count());
    }

    public function test_unknown_role_is_denied_and_persisted_role_changes_apply_to_next_request(): void
    {
        $user = $this->user(User::ROLE_ADMIN);
        $this->post('/login', ['email' => $user->email, 'password' => $this->password]);
        $this->get('/usuarios')->assertOk();

        DB::table('users')->where('id', $user->id)->update(['role' => User::ROLE_OBSERVER]);
        Auth::forgetGuards();
        $this->get('/usuarios')->assertForbidden();
        $this->get('/cpu-memoria')->assertOk();

        DB::table('users')->where('id', $user->id)->update(['role' => 'unrecognized']);
        Auth::forgetGuards();
        $this->post('/procesos/prueba')->assertForbidden();
        $this->get('/cpu-memoria')->assertOk();
    }

    public function test_user_management_does_not_mass_assign_role_or_password_and_hashes_new_passwords(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $target = $this->user(User::ROLE_OBSERVER);
        $oldHash = $target->password;
        $attemptedPassword = 'Attacker-Controlled-Password-123!';

        $this->actingAs($admin, 'web')->patch(route('users.update', $target), [
            'name' => 'Safe Updated Name',
            'email' => 'safe-updated@example.test',
            'role' => User::ROLE_ADMIN,
            'password' => $attemptedPassword,
        ])->assertRedirect(route('users.edit', $target));

        $target->refresh();
        $this->assertSame(User::ROLE_OBSERVER, $target->role);
        $this->assertSame($oldHash, $target->password);

        $newPassword = 'New-'.bin2hex(random_bytes(16)).'Aa1!';
        $this->post(route('users.store'), [
            'name' => 'Created Safely', 'email' => 'created-safely@example.test',
            'password' => $newPassword, 'password_confirmation' => $newPassword,
            'role' => User::ROLE_OBSERVER,
        ])->assertRedirect(route('users.index'));

        $created = User::query()->where('email', 'created-safely@example.test')->sole();
        $this->assertTrue(Hash::check($newPassword, $created->password));
        $this->assertNotSame($newPassword, $created->password);
        $this->assertArrayNotHasKey('password', $created->toArray());
        $this->assertArrayNotHasKey('remember_token', $created->toArray());
        $this->assertStringNotContainsString($newPassword, AuditLog::query()->get()->toJson());
    }

    public function test_last_administrator_is_preserved_and_observer_cannot_change_roles(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $target = $this->user(User::ROLE_OBSERVER);
        $this->actingAs($admin, 'web');

        $this->from(route('users.edit', $admin))->patch(route('users.role', $admin), ['role' => User::ROLE_OBSERVER])
            ->assertRedirect(route('users.edit', $admin))
            ->assertSessionHas('error', 'Debe permanecer al menos un Administrador activo.');
        $this->assertSame(User::ROLE_ADMIN, $admin->fresh()->role);

        Auth::forgetGuards();
        $this->actingAs($target, 'web')->patch(route('users.role', $admin), ['role' => User::ROLE_ADMIN])
            ->assertForbidden();
        $this->assertSame(User::ROLE_ADMIN, $admin->fresh()->role);
    }

    public function test_audit_metadata_is_allowlisted_and_audit_records_cannot_be_finalized_twice(): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN), 'web');
        $sensitivePassword = 'Audit-Secret-'.bin2hex(random_bytes(8));
        $entry = app(AuditLogger::class)->begin('user.update', 'user', 'target-1', metadata: [
            'process_id' => 27,
            'password' => $sensitivePassword,
            'token' => 'private-token-value',
            'request_body' => 'private request data',
        ]);

        $this->assertSame(['process_id' => 27], $entry->metadata);
        $this->assertStringNotContainsString($sensitivePassword, $entry->toJson());
        $this->assertStringNotContainsString('private-token-value', $entry->toJson());
        $this->assertStringNotContainsString('private request data', $entry->toJson());

        app(AuditLogger::class)->finish($entry, AuditLog::STATUS_SUCCESS, 'updated');
        $this->expectException(\LogicException::class);
        app(AuditLogger::class)->finish($entry, AuditLog::STATUS_SUCCESS, 'updated');
    }

    public function test_audit_begin_failure_prevents_process_operation(): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN), 'web');
        $this->mock(AuditLogger::class, function (MockInterface $mock): void {
            $mock->shouldReceive('begin')->once()->andThrow(new \RuntimeException('private audit backend detail'));
        });
        $this->mock(TestProcessService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('launch');
        });

        $this->followingRedirects()->post('/procesos/prueba')->assertOk()
            ->assertSeeText('No se pudo registrar la operación en la bitácora.')
            ->assertDontSeeText('private audit backend detail');
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_audit_finish_failure_never_repeats_the_process_operation(): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN), 'web');
        $entry = app(AuditLogger::class)->begin('process.start', 'test_process');
        $this->mock(AuditLogger::class, function (MockInterface $mock) use ($entry): void {
            $mock->shouldReceive('begin')->once()->andReturn($entry);
            $mock->shouldReceive('finish')->once()->andThrow(new \RuntimeException('private audit backend detail'));
        });
        $this->mock(TestProcessService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('launch')->once()->andThrow(new \RuntimeException('process unavailable'));
        });

        $this->followingRedirects()->post('/procesos/prueba')->assertOk()
            ->assertSeeText('No se pudo confirmar el resultado de la operación en la bitácora.')
            ->assertDontSeeText('private audit backend detail')
            ->assertDontSeeText('process unavailable');
        $this->assertSame(AuditLog::STATUS_PENDING, $entry->fresh()->status);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_initial_admin_command_rejects_a_second_initialization_without_secrets_in_output(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $existingHash = $admin->password;
        $newPassword = 'Second-'.bin2hex(random_bytes(16)).'Aa1!';

        $this->artisan('sysmonitor:create-admin')
            ->expectsQuestion('Nombre del Administrador', 'Second Admin')
            ->expectsQuestion('Correo electrónico', 'second-admin@example.test')
            ->expectsQuestion('Contraseña (mínimo 12 caracteres)', $newPassword)
            ->expectsQuestion('Confirma la contraseña', $newPassword)
            ->expectsOutput('Ya existe una cuenta Administrador; no se realizaron cambios.')
            ->doesntExpectOutput($newPassword)
            ->assertExitCode(1);

        $this->assertSame(1, User::query()->count());
        $this->assertSame($existingHash, $admin->fresh()->password);
        $definition = app(CreateInitialAdmin::class)->getDefinition();
        $this->assertFalse($definition->hasArgument('password'));
        $this->assertFalse($definition->hasOption('password'));
    }

    public function test_all_36_routes_keep_auth_boundaries_and_storage_keeps_independent_http_basic(): void
    {
        $this->assertCount(36, Route::getRoutes());
        foreach (Route::getRoutes() as $route) {
            $middleware = $route->gatherMiddleware();
            if (in_array($route->uri(), ['login', 'logout', 'up'], true)) {
                $this->assertNotContains('auth:web', $middleware, $route->uri());

                continue;
            }

            $this->assertContains('auth:web', $middleware, $route->uri());
            if (str_starts_with((string) $route->getName(), 'users.')
                || array_intersect(['POST', 'PUT', 'PATCH', 'DELETE'], $route->methods())) {
                $this->assertContains(EnsureUserIsAdmin::class, $middleware, $route->uri());
            }
        }

        $this->assertContains(AuthenticateStorage::class, Route::getRoutes()->getByName('storage.index')->gatherMiddleware());
        $this->actingAs($this->user(User::ROLE_ADMIN), 'web');
        config(['storage_access.username' => null, 'storage_access.password_hash' => null]);
        $this->mock(StorageController::class)->shouldNotReceive('index');
        $this->get('/almacenamiento')->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Basic realm="SysMonitor M5", charset="UTF-8"');
    }

    public function test_csrf_is_enforced_for_login_logout_and_administrative_writes(): void
    {
        $this->app->instance('env', 'production');
        $this->post('/login', ['email' => 'nobody@example.test', 'password' => 'not-a-real-password'])
            ->assertStatus(419);

        $this->actingAs($this->user(User::ROLE_ADMIN), 'web');
        $this->post('/logout')->assertStatus(419);
        $this->post('/procesos/prueba')->assertStatus(419);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    private function user(string $role = User::ROLE_OBSERVER): User
    {
        $user = new User;
        $user->name = 'M6 Security Test';
        $user->email = uniqid('m6-security-', true).'@example.test';
        $user->password = $this->password;
        $user->role = $role;
        $user->save();

        return $user;
    }
}
