<?php

namespace Tests\Feature\M6;

use App\Http\Middleware\EnsureUserIsAdmin;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\M6\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private string $password;

    protected function setUp(): void
    {
        parent::setUp();
        $this->password = 'Test-'.bin2hex(random_bytes(16)).'Aa1!';
    }

    public function test_guest_observer_and_unknown_role_cannot_manage_users(): void
    {
        $this->get(route('users.index'))->assertRedirect(route('login'));
        $this->post(route('users.store'))->assertRedirect(route('login'));

        $this->actingAs($this->user(User::ROLE_OBSERVER), 'web');
        $this->get(route('users.index'))->assertForbidden();
        $this->post(route('users.store'), $this->validPayload())->assertForbidden();
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('audit_logs', 0);

        $unknown = $this->user(User::ROLE_OBSERVER);
        $unknown->setRawAttributes(array_replace($unknown->getAttributes(), ['role' => 'unknown-role']));
        $unknown->save();
        $this->actingAs($unknown, 'web');
        $this->get(route('users.index'))->assertForbidden();
        $this->post(route('users.store'), $this->validPayload())->assertForbidden();
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_admin_list_uses_shared_layout_and_never_selects_or_renders_passwords(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $this->user(User::ROLE_OBSERVER, 'Observer Display');
        $this->actingAs($admin, 'web');

        $response = $this->get(route('users.index'))->assertOk()
            ->assertViewIs('m6.users.index')
            ->assertSee('<div class="sysmonitor-app">', false)
            ->assertSeeText('Usuarios')
            ->assertSee('href="'.route('users.index').'"', false)
            ->assertSeeText('Observer Display')
            ->assertSeeText('Observador')
            ->assertDontSee($this->password)
            ->assertDontSee(Hash::make($this->password));

        $selected = $response->viewData('users')->getCollection()->first()->getAttributes();
        $this->assertArrayNotHasKey('password', $selected);
        $this->assertStringContainsString(route('users.index'), $response->getContent());
    }

    public function test_admin_creates_users_with_unique_email_validated_role_and_hashed_password(): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN), 'web');

        $this->post(route('users.store'), $this->validPayload())->assertRedirect(route('users.index'));
        $created = User::query()->where('email', 'new.user@example.test')->sole();
        $this->assertSame(User::ROLE_OBSERVER, $created->role);
        $this->assertTrue(Hash::check($this->password, $created->password));
        $this->assertNotSame($this->password, $created->password);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.create', 'target_id' => (string) $created->id, 'status' => AuditLog::STATUS_SUCCESS]);

        $this->from(route('users.create'))->post(route('users.store'), $this->validPayload(['name' => 'Duplicate']))
            ->assertSessionHasErrors('email');
        $this->post(route('users.store'), $this->validPayload(['email' => 'bad-role@example.test', 'role' => 'superadmin']))
            ->assertSessionHasErrors('role');
        $this->assertSame(2, User::query()->count());
    }

    public function test_admin_can_edit_profile_without_mass_assigning_role_or_password(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $target = $this->user(User::ROLE_OBSERVER);
        $originalHash = $target->password;
        $this->actingAs($admin, 'web');

        $this->patch(route('users.update', $target), [
            'name' => 'Updated Name', 'email' => 'updated@example.test',
            'role' => User::ROLE_ADMIN, 'password' => 'Attacker-Controlled-Password',
        ])->assertRedirect(route('users.edit', $target));

        $target->refresh();
        $this->assertSame('Updated Name', $target->name);
        $this->assertSame('updated@example.test', $target->email);
        $this->assertSame(User::ROLE_OBSERVER, $target->role);
        $this->assertSame($originalHash, $target->password);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.update', 'target_id' => (string) $target->id, 'status' => AuditLog::STATUS_SUCCESS]);
    }

    public function test_role_change_is_audited_and_last_administrator_cannot_be_demoted(): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN), 'web');
        $secondAdmin = $this->user(User::ROLE_ADMIN);

        $this->patch(route('users.role', $secondAdmin), ['role' => User::ROLE_OBSERVER])
            ->assertRedirect(route('users.edit', $secondAdmin));
        $this->assertSame(User::ROLE_OBSERVER, $secondAdmin->fresh()->role);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.role', 'target_id' => (string) $secondAdmin->id, 'status' => AuditLog::STATUS_SUCCESS]);

        $lastAdmin = User::query()->where('role', User::ROLE_ADMIN)->sole();
        $this->from(route('users.edit', $lastAdmin))->patch(route('users.role', $lastAdmin), ['role' => User::ROLE_OBSERVER])
            ->assertRedirect(route('users.edit', $lastAdmin))->assertSessionHas('error', 'Debe permanecer al menos un Administrador activo.');
        $this->assertSame(User::ROLE_ADMIN, $lastAdmin->fresh()->role);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.role', 'target_id' => (string) $lastAdmin->id, 'status' => AuditLog::STATUS_FAILURE]);
    }

    public function test_administrator_can_demote_self_and_finish_the_same_audit_record(): void
    {
        $adminA = $this->user(User::ROLE_ADMIN, 'Administrator A');
        $adminB = $this->user(User::ROLE_ADMIN, 'Administrator B');

        $this->post('/login', ['email' => $adminA->email, 'password' => $this->password])
            ->assertRedirect(route('processes.index'));

        $this->from(route('users.edit', $adminA))
            ->patch(route('users.role', $adminA), ['role' => User::ROLE_OBSERVER])
            ->assertRedirect(route('users.edit', $adminA))
            ->assertSessionHas('status', 'Rol actualizado.');

        $this->assertSame(User::ROLE_OBSERVER, $adminA->fresh()->role);
        $this->assertSame(User::ROLE_ADMIN, $adminB->fresh()->role);

        $audit = AuditLog::query()
            ->where('action', 'user.role')
            ->where('user_id', $adminA->id)
            ->where('target_id', (string) $adminA->id)
            ->sole();
        $this->assertSame(AuditLog::STATUS_SUCCESS, $audit->status);
        $this->assertSame('role_changed', $audit->result);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'user.role',
            'user_id' => $adminA->id,
            'target_id' => (string) $adminA->id,
            'status' => AuditLog::STATUS_PENDING,
        ]);

        auth('web')->logout();
        $this->post('/login', ['email' => $adminA->email, 'password' => $this->password])
            ->assertRedirect(route('processes.index'));
        $this->post(route('users.store'), $this->validPayload(['email' => 'after-demotion@example.test']))
            ->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'after-demotion@example.test']);
    }

    public function test_observer_without_the_operation_continuation_cannot_finish_an_admin_audit(): void
    {
        $admin = $this->user(User::ROLE_ADMIN, 'Audit Owner');
        $observer = $this->user(User::ROLE_OBSERVER, 'Unrelated Observer');
        $this->actingAs($admin, 'web');

        $logger = app(AuditLogger::class);
        $pending = $logger->begin('user.role', 'user', $admin->id);

        $this->actingAs($observer, 'web');
        try {
            $logger->finish($pending, AuditLog::STATUS_SUCCESS, 'role_changed');
            $this->fail('An unrelated Observer finalized an Administrator audit.');
        } catch (AuthorizationException) {
            $this->assertSame(AuditLog::STATUS_PENDING, $pending->fresh()->status);
        }
    }

    public function test_password_change_is_confirmed_hashed_and_never_exposed_or_audited(): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN), 'web');
        $target = $this->user(User::ROLE_OBSERVER);
        $newPassword = 'New-'.bin2hex(random_bytes(16)).'Aa1!';

        $this->get(route('users.password.edit', $target))->assertOk()
            ->assertDontSee($target->password)
            ->assertDontSee('name="current_password"', false);
        $this->put(route('users.password.update', $target), [
            'password' => $newPassword, 'password_confirmation' => $newPassword,
        ])->assertRedirect(route('users.edit', $target));

        $target->refresh();
        $this->assertTrue(Hash::check($newPassword, $target->password));
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.password', 'target_id' => (string) $target->id, 'status' => AuditLog::STATUS_SUCCESS]);
        $this->assertStringNotContainsString($newPassword, AuditLog::query()->sole()->toJson());
        $this->from(route('users.password.edit', $target))->put(route('users.password.update', $target), [
            'password' => $newPassword, 'password_confirmation' => 'Mismatch-Password-123!',
        ])->assertSessionHasErrors('password');
    }

    public function test_begin_failure_prevents_user_mutation(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $target = $this->user(User::ROLE_OBSERVER);
        $this->actingAs($admin, 'web');
        $this->mock(AuditLogger::class, function (MockInterface $mock): void {
            $mock->shouldReceive('begin')->once()->andThrow(new RuntimeException('private storage detail'));
        });
        $this->from(route('users.edit', $target))->patch(route('users.update', $target), [
            'name' => 'Must Not Change', 'email' => 'must-not-change@example.test',
        ])->assertRedirect(route('users.edit', $target));
        $this->assertSame('M6 Test User', $target->fresh()->name);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_finish_failure_never_repeats_mutation_and_leaves_pending_entry(): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN), 'web');
        $target = $this->user(User::ROLE_OBSERVER);
        $pending = app(AuditLogger::class)->begin('user.update', 'user', $target->id);
        $this->mock(AuditLogger::class, function (MockInterface $mock) use ($pending): void {
            $mock->shouldReceive('begin')->once()->andReturn($pending);
            $mock->shouldReceive('finish')->once()->andThrow(new RuntimeException('private audit detail'));
        });
        $this->patch(route('users.update', $target), [
            'name' => 'Changed Once', 'email' => 'changed-once@example.test',
        ])->assertRedirect(route('users.edit', $target));
        $this->assertSame('Changed Once', $target->fresh()->name);
        $this->assertSame(1, User::query()->where('email', 'changed-once@example.test')->count());
        $this->assertSame(AuditLog::STATUS_PENDING, $pending->fresh()->status);
    }

    public function test_csrf_and_public_registration_remain_disabled(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $this->actingAs($admin, 'web');
        $this->app->instance('env', 'production');
        $this->post(route('users.store'), $this->validPayload())->assertStatus(419);
        $this->get('/register')->assertNotFound();
        auth('web')->logout();
        $this->get('/login')->assertOk()->assertDontSee('/register');
        $this->assertSame(1, User::query()->count());
    }

    public function test_management_routes_are_admin_protected_and_original_routes_remain_registered(): void
    {
        foreach (['users.index', 'users.create', 'users.store', 'users.edit', 'users.update', 'users.role', 'users.password.edit', 'users.password.update'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $this->assertContains('auth:web', $route->gatherMiddleware(), $name);
            $this->assertContains(EnsureUserIsAdmin::class, $route->gatherMiddleware(), $name);
        }

        $this->assertNotNull(Route::getRoutes()->getByName('storage.index'));
        $this->assertNotNull(Route::getRoutes()->getByName('processes.index'));
        $this->assertNotNull(Route::getRoutes()->getByName('cpu-memory.index'));
        $this->assertNotNull(Route::getRoutes()->getByName('scheduling.index'));
        $this->assertNotNull(Route::getRoutes()->getByName('deadlocks.index'));
        $this->assertSame(36, Route::getRoutes()->count());
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
    }

    public function test_user_navigation_is_visible_only_to_administrators(): void
    {
        $this->actingAs($this->user(User::ROLE_OBSERVER), 'web');
        $this->get('/planificacion')->assertOk()->assertDontSee('href="'.route('users.index').'"', false);

        auth('web')->logout();
        $this->actingAs($this->user(User::ROLE_ADMIN), 'web');
        $this->get('/planificacion')->assertOk()->assertSee('href="'.route('users.index').'"', false);
    }

    private function user(string $role, string $name = 'M6 Test User'): User
    {
        $user = new User;
        $user->name = $name;
        $user->email = uniqid('user-management-', true).'@example.test';
        $user->password = $this->password;
        $user->role = $role;
        $user->save();

        return $user;
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'New User', 'email' => 'new.user@example.test',
            'password' => $this->password, 'password_confirmation' => $this->password,
            'role' => User::ROLE_OBSERVER,
        ], $overrides);
    }
}
