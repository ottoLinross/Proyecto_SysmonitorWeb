<?php

namespace Tests\Feature\M6;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\M6\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'm6_audit_testing',
            'database.connections.m6_audit_testing' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
            ],
        ]);

        $connection = DB::connection();
        $this->assertTrue($this->app->environment('testing'));
        $this->assertSame('sqlite', $connection->getDriverName());
        $this->assertSame(':memory:', $connection->getDatabaseName());
        (require database_path('migrations/0001_01_01_000000_create_users_table.php'))->up();
        (require database_path('migrations/2026_10_24_000000_add_role_to_users_table.php'))->up();
        $this->auditMigration()->up();
    }

    protected function tearDown(): void
    {
        DB::purge('m6_audit_testing');
        parent::tearDown();
    }

    public function test_creates_pending_record_with_admin_action_pid_ip_and_json_metadata(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'web');
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.12']);

        $entry = app(AuditLogger::class)->begin('process.signal', 'process', 42, 42, [
            'signal' => 'TERM', 'process_id' => 42, 'password' => 'should-not-persist', 'Authorization' => 'secret',
        ]);

        $this->assertSame($admin->id, $entry->user_id);
        $this->assertSame('process.signal', $entry->action);
        $this->assertSame('42', $entry->target_id);
        $this->assertSame(42, $entry->pid);
        $this->assertSame(AuditLog::STATUS_PENDING, $entry->status);
        $this->assertNotNull($entry->ip_address);
        $this->assertNotFalse(filter_var($entry->ip_address, FILTER_VALIDATE_IP));
        $this->assertSame(['process_id' => 42, 'signal' => 'TERM'], $entry->metadata);
        $this->assertNotNull($entry->created_at);
        $this->assertSame(1, DB::table('audit_logs')->count());
    }

    public function test_finishes_as_success_or_failure_once_and_rejects_invalid_status(): void
    {
        $this->actingAs($this->admin(), 'web');
        $logger = app(AuditLogger::class);
        $success = $logger->begin('process.start');
        $failure = $logger->begin('process.stop');

        $this->assertSame(AuditLog::STATUS_SUCCESS, $logger->finish($success, AuditLog::STATUS_SUCCESS, 'started')->status);
        $this->assertSame('started', $success->fresh()->result);
        $this->assertSame(AuditLog::STATUS_FAILURE, $logger->finish($failure, AuditLog::STATUS_FAILURE, 'operation_failed')->status);
        $this->expectException(InvalidArgumentException::class);
        $logger->finish($success, 'unknown');
    }

    public function test_rejects_guest_observer_unpersisted_and_another_admin_finishing_record(): void
    {
        try {
            app(AuditLogger::class)->begin('process.start');
            $this->fail('Guest was accepted.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('audit_logs', 0);
        }

        $observer = $this->user();
        $this->actingAs($observer, 'web');
        try {
            app(AuditLogger::class)->begin('process.start');
            $this->fail('Observer was accepted.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('audit_logs', 0);
        }

        $admin = $this->admin();
        $this->actingAs($admin, 'web');
        $entry = app(AuditLogger::class)->begin('process.start');
        $other = $this->admin();
        $this->actingAs($other, 'web');
        $this->expectException(AuthorizationException::class);
        app(AuditLogger::class)->finish($entry, AuditLog::STATUS_SUCCESS);
    }

    public function test_rechecks_the_persisted_role_instead_of_trusting_a_stale_authenticated_model(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'web');
        $admin->role = User::ROLE_OBSERVER;
        $admin->save();

        $this->expectException(AuthorizationException::class);
        app(AuditLogger::class)->begin('process.start');
    }

    public function test_mass_assignment_is_disabled_and_model_rejects_unknown_status(): void
    {
        $this->assertFalse((new AuditLog)->isFillable('user_id'));
        $this->assertFalse((new AuditLog)->isFillable('status'));
        $log = new AuditLog;
        $this->expectException(InvalidArgumentException::class);
        $log->status = 'unknown';
    }

    public function test_unknown_and_sensitive_metadata_are_dropped_and_allowed_json_persists(): void
    {
        $this->actingAs($this->admin(), 'web');
        $entry = app(AuditLogger::class)->begin('scheduler.run', metadata: [
            'algorithm' => 'round_robin', 'quantum' => 4, 'password' => 'x', 'token' => 'y', 'arbitrary' => 'z',
            'nested' => ['secret' => 'x'], 'cookie' => 'c',
        ]);
        $this->assertSame(['algorithm' => 'round_robin', 'quantum' => 4], $entry->fresh()->metadata);
        $this->assertStringNotContainsString('secret', json_encode($entry->metadata));
    }

    public function test_pending_insert_failure_propagates_before_dependent_operation_can_run(): void
    {
        $this->actingAs($this->admin(), 'web');
        Schema::drop('audit_logs');
        $operationRan = false;
        try {
            $pending = app(AuditLogger::class)->begin('process.start');
            $operationRan = true;
        } catch (QueryException) {
            // A caller must invoke its dependent operation only after begin() returns.
        }

        $this->assertFalse($operationRan);
        $this->assertFalse(isset($pending));
    }

    public function test_finalization_failure_leaves_pending_row_for_investigation(): void
    {
        $this->actingAs($this->admin(), 'web');
        $entry = app(AuditLogger::class)->begin('process.start');
        DB::statement("CREATE TRIGGER fail_audit_update BEFORE UPDATE ON audit_logs BEGIN SELECT RAISE(ABORT, 'blocked'); END");

        try {
            app(AuditLogger::class)->finish($entry, AuditLog::STATUS_SUCCESS);
            $this->fail('Finalization unexpectedly succeeded.');
        } catch (QueryException) {
            $this->assertSame(AuditLog::STATUS_PENDING, DB::table('audit_logs')->where('id', $entry->id)->value('status'));
        }
    }

    public function test_history_survives_user_deletion_and_migration_is_reversible(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'web');
        $entry = app(AuditLogger::class)->begin('process.start');
        $admin->delete();

        $this->assertDatabaseHas('audit_logs', ['id' => $entry->id, 'user_id' => null]);
        $this->assertNull($entry->fresh()->user);
        $this->auditMigration()->down();
        $this->assertFalse(Schema::hasTable('audit_logs'));
        $this->assertTrue(Schema::hasTable('users'));
    }

    public function test_action_and_result_inputs_are_bounded(): void
    {
        $this->actingAs($this->admin(), 'web');
        $logger = app(AuditLogger::class);
        try {
            $logger->begin(str_repeat('a', 81));
            $this->fail('Oversized action was accepted.');
        } catch (InvalidArgumentException) {
            $this->assertDatabaseCount('audit_logs', 0);
        }
        $entry = $logger->begin('process.start');
        $this->expectException(InvalidArgumentException::class);
        $logger->finish($entry, AuditLog::STATUS_SUCCESS, 'Exception: /secret/path');
    }

    private function admin(): User
    {
        $user = $this->user();
        $user->role = User::ROLE_ADMIN;
        $user->save();

        return $user;
    }

    private function user(): User
    {
        $user = new User;
        $user->name = 'M6 Audit Test';
        $user->email = uniqid('m6-audit-', true).'@example.test';
        $user->setRawAttributes(array_replace($user->getAttributes(), ['password' => '']));
        $user->save();

        return $user;
    }

    private function auditMigration(): Migration
    {
        return require database_path('migrations/2026_10_29_000000_create_audit_logs_table.php');
    }
}
