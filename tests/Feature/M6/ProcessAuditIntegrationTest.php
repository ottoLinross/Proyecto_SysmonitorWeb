<?php

namespace Tests\Feature\M6;

use App\Models\AuditLog;
use App\Models\ManagedProcess;
use App\Models\User;
use App\Services\M6\AuditLogger;
use App\Services\System\ProcessPriorityService;
use App\Services\System\ProcessSignalService;
use App\Services\System\TestProcessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class ProcessAuditIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_process_start_is_pending_before_launch_and_finalized_with_managed_id_and_pid(): void
    {
        $this->actingAs($this->admin(), 'web');
        $this->mock(TestProcessService::class, function (MockInterface $mock) {
            $mock->shouldReceive('launch')->once()->andReturnUsing(function () {
                $this->assertDatabaseHas('audit_logs', ['action' => 'process.start', 'status' => AuditLog::STATUS_PENDING]);

                return $this->process();
            });
        });

        $this->post('/procesos/prueba')->assertRedirect(route('processes.index'));

        $entry = AuditLog::query()->sole();
        $this->assertSame('process.start', $entry->action);
        $this->assertSame('test_process', $entry->target_type);
        $this->assertSame((string) $entry->target_id, (string) $this->processRowId());
        $this->assertSame(4242, $entry->pid);
        $this->assertSame(AuditLog::STATUS_SUCCESS, $entry->status);
        $this->assertSame('completed', $entry->result);
    }

    public function test_signal_is_pending_before_service_and_records_whitelisted_metadata(): void
    {
        $this->actingAs($this->admin(), 'web');
        $process = $this->process();
        $this->mock(ProcessSignalService::class, function (MockInterface $mock) use ($process) {
            $mock->shouldReceive('send')->withArgs(fn ($model, $signal) => $model->is($process) && $signal === 'term')->once()
                ->andReturnUsing(function () use ($process) {
                    $this->assertDatabaseHas('audit_logs', [
                        'action' => 'process.signal', 'target_id' => (string) $process->id, 'status' => AuditLog::STATUS_PENDING,
                    ]);

                    return ['success' => true, 'pid' => 4242, 'message' => 'signal sent'];
                });
        });

        $this->post(route('processes.test.signal', $process), ['signal' => 'term', 'password' => 'never-log'])
            ->assertRedirect(route('processes.index'));

        $entry = AuditLog::query()->sole();
        $this->assertSame(AuditLog::STATUS_SUCCESS, $entry->status);
        $this->assertSame((string) $process->id, $entry->target_id);
        $this->assertSame(4242, $entry->pid);
        $this->assertSame(['signal' => 'term'], $entry->metadata);
        $this->assertSame('completed', $entry->result);
    }

    public function test_priority_is_pending_before_service_and_records_validated_nice(): void
    {
        $this->actingAs($this->admin(), 'web');
        $process = $this->process();
        $this->mock(ProcessPriorityService::class, function (MockInterface $mock) use ($process) {
            $mock->shouldReceive('change')->withArgs(fn ($model, $nice) => $model->is($process) && $nice === 5)->once()->andReturnUsing(function () use ($process) {
                $this->assertDatabaseHas('audit_logs', [
                    'action' => 'process.priority', 'target_id' => (string) $process->id, 'status' => AuditLog::STATUS_PENDING,
                ]);

                return ['success' => true, 'pid' => 4242, 'message' => 'priority changed'];
            });
        });

        $this->post(route('processes.test.priority', $process), ['nice' => '5', 'token' => 'never-log'])
            ->assertRedirect(route('processes.index'));

        $entry = AuditLog::query()->sole();
        $this->assertSame(AuditLog::STATUS_SUCCESS, $entry->status);
        $this->assertSame(4242, $entry->pid);
        $this->assertSame(['nice' => 5], $entry->metadata);
    }

    public function test_operation_exception_is_finalized_as_failure_without_exposing_internal_details(): void
    {
        $this->actingAs($this->admin(), 'web');
        $process = $this->process();
        $this->mock(ProcessSignalService::class, function (MockInterface $mock) use ($process) {
            $mock->shouldReceive('send')->withArgs(fn ($model, $signal) => $model->is($process) && $signal === 'kill')->once()->andThrow(new RuntimeException('internal secret detail'));
        });

        $response = $this->followingRedirects()->post(route('processes.test.signal', $process), ['signal' => 'kill']);

        $response->assertOk()->assertSeeText('No se pudo completar la acción del proceso de prueba.')
            ->assertDontSeeText('internal secret detail');
        $entry = AuditLog::query()->sole();
        $this->assertSame(AuditLog::STATUS_FAILURE, $entry->status);
        $this->assertSame('operation_failed', $entry->result);
    }

    public function test_begin_failure_prevents_operation_and_returns_a_safe_error(): void
    {
        $this->actingAs($this->admin(), 'web');
        $this->mock(AuditLogger::class, function (MockInterface $mock) {
            $mock->shouldReceive('begin')->once()->andThrow(new RuntimeException('database internals'));
        });
        $this->mock(TestProcessService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('launch');
        });

        $this->followingRedirects()->post('/procesos/prueba')->assertOk()
            ->assertSeeText('No se pudo registrar la operación en la bitácora.')
            ->assertDontSeeText('database internals');
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_finish_failure_never_repeats_operation_and_leaves_pending_entry(): void
    {
        $this->actingAs($this->admin(), 'web');
        $entry = app(AuditLogger::class)->begin('process.start', 'test_process');
        $this->mock(AuditLogger::class, function (MockInterface $mock) use ($entry) {
            $mock->shouldReceive('begin')->once()->andReturn($entry);
            $mock->shouldReceive('finish')->once()->andThrow(new RuntimeException('audit persistence detail'));
        });
        $this->mock(TestProcessService::class, function (MockInterface $mock) {
            $mock->shouldReceive('launch')->once()->andReturn($this->process());
        });

        $response = $this->followingRedirects()->post('/procesos/prueba');

        $response->assertOk()->assertSeeText('No se pudo confirmar el resultado de la operación en la bitácora.')
            ->assertDontSeeText('audit persistence detail');
        $this->assertSame(AuditLog::STATUS_PENDING, $entry->fresh()->status);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_invalid_inputs_and_non_admin_users_do_not_create_audit_or_call_services(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'web');
        $process = $this->process();
        $this->mock(ProcessSignalService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('send');
        });
        $this->mock(ProcessPriorityService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('change');
        });

        $this->post(route('processes.test.signal', $process), ['signal' => 'USR1'])->assertRedirect(route('processes.index'));
        $this->post(route('processes.test.priority', $process), ['nice' => '20'])->assertRedirect(route('processes.index'));
        $this->assertDatabaseCount('audit_logs', 0);

        $observer = $this->user();
        $this->actingAs($observer, 'web');
        $this->post(route('processes.test.signal', $process), ['signal' => 'term'])->assertForbidden();
        $this->post('/procesos/prueba')->assertForbidden();
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_guest_cannot_create_audit_or_run_process_operations(): void
    {
        $this->mock(TestProcessService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('launch');
        });

        $this->post('/procesos/prueba')->assertRedirect(route('login'));
        $this->assertDatabaseCount('audit_logs', 0);
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
        $user->name = 'M6 Process Audit';
        $user->email = uniqid('m6-process-audit-', true).'@example.test';
        $user->password = '';
        $user->save();

        return $user;
    }

    private function process(): ManagedProcess
    {
        return ManagedProcess::query()->create([
            'pid' => 4242,
            'process_type' => 'sleep',
            'command_label' => '/usr/bin/sleep 300',
            'owner_uid' => 1000,
            'start_time_ticks' => random_int(1000, 999999),
            'status' => 'running',
            'launched_at' => now(),
        ]);
    }

    private function processRowId(): int
    {
        return (int) ManagedProcess::query()->latest('id')->value('id');
    }
}
