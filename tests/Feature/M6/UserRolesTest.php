<?php

namespace Tests\Feature\M6;

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UserRolesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'm6_roles_testing',
            'database.connections.m6_roles_testing' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);

        $connection = DB::connection();
        $this->assertTrue($this->app->environment('testing'));
        $this->assertSame('sqlite', $connection->getDriverName());
        $this->assertSame(':memory:', $connection->getDatabaseName());

        $migration = require database_path('migrations/0001_01_01_000000_create_users_table.php');
        $migration->up();
    }

    protected function tearDown(): void
    {
        DB::purge('m6_roles_testing');

        parent::tearDown();
    }

    public function test_admin_is_recognized_and_persisted(): void
    {
        $this->roleMigration()->up();
        $user = $this->newUser();
        $user->role = User::ROLE_ADMIN;
        $user->save();

        $saved = $user->fresh();
        $this->assertSame('admin', $saved->role);
        $this->assertTrue($saved->isAdmin());
        $this->assertFalse($saved->isObserver());
    }

    public function test_new_users_default_to_observer_before_and_after_persistence(): void
    {
        $this->roleMigration()->up();
        $user = $this->newUser();
        $this->assertSame(User::ROLE_OBSERVER, $user->role);
        $this->assertTrue($user->isObserver());
        $this->assertFalse($user->isAdmin());
        $user->save();

        $this->assertSame('observer', $user->fresh()->role);
        $this->assertTrue($user->fresh()->isObserver());
    }

    public function test_database_default_also_applies_to_inserts_without_a_role(): void
    {
        $this->roleMigration()->up();
        $id = DB::table('users')->insertGetId($this->userData());

        $this->assertSame('observer', DB::table('users')->where('id', $id)->value('role'));
    }

    #[DataProvider('invalidRoles')]
    public function test_explicit_assignment_rejects_invalid_roles(mixed $role): void
    {
        $user = new User;

        try {
            $user->role = $role;
            $this->fail('Invalid role was accepted.');
        } catch (InvalidArgumentException) {
            $this->assertSame(User::ROLE_OBSERVER, $user->role);
        }
    }

    public static function invalidRoles(): array
    {
        return array_map(fn (mixed $role): array => [$role], [
            'ADMIN', 'Observer', ' admin', 'observer ', '', 'superadmin', null, true, false, 0, 1, [],
        ]);
    }

    #[DataProvider('invalidRoles')]
    public function test_role_checks_do_not_coerce_raw_values(mixed $role): void
    {
        $user = new User;
        $user->setRawAttributes(['role' => $role]);

        $this->assertFalse($user->isAdmin());
        $this->assertFalse($user->isObserver());
    }

    public function test_mass_assignment_cannot_grant_or_replace_a_role(): void
    {
        $this->roleMigration()->up();
        $user = $this->newUser();
        $this->assertFalse($user->isFillable('role'));
        $user->fill(['role' => User::ROLE_ADMIN]);
        $user->save();
        $this->assertTrue($user->fresh()->isObserver());

        $user->update(['role' => User::ROLE_ADMIN]);
        $this->assertTrue($user->fresh()->isObserver());

        $user->role = User::ROLE_ADMIN;
        $user->save();
        $user->update(['role' => User::ROLE_OBSERVER]);
        $this->assertTrue($user->fresh()->isAdmin());
    }

    public function test_migration_preserves_existing_users_and_rollback_only_removes_role(): void
    {
        $id = DB::table('users')->insertGetId($this->userData());
        $before = (array) DB::table('users')->where('id', $id)->first();
        $migration = $this->roleMigration();
        $migration->up();

        $after = (array) DB::table('users')->where('id', $id)->first();
        $this->assertSame('observer', $after['role']);
        unset($after['role']);
        $this->assertSame($before, $after);

        $migration->down();
        $this->assertFalse(Schema::hasColumn('users', 'role'));
        $this->assertSame($before, (array) DB::table('users')->where('id', $id)->first());
        $this->assertSame(1, DB::table('users')->count());
        $this->assertTrue(Schema::hasTable('sessions'));
        $this->assertTrue(Schema::hasTable('password_reset_tokens'));
    }

    private function roleMigration(): Migration
    {
        return require database_path('migrations/2026_10_24_000000_add_role_to_users_table.php');
    }

    private function newUser(): User
    {
        $user = new User;
        $user->name = 'M6 Test';
        $user->email = 'm6@example.test';

        // No credentials are generated or stored by these role tests.
        $user->setRawAttributes(array_replace($user->getAttributes(), ['password' => '']));

        return $user;
    }

    private function userData(): array
    {
        return ['name' => 'M6 Test', 'email' => 'm6@example.test', 'password' => ''];
    }
}
