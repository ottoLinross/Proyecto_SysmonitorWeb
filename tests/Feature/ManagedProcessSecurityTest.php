<?php

namespace Tests\Feature;

use App\Models\ManagedProcess;
use App\Services\System\ApplicationProcessUid;
use App\Services\System\ManagedProcessProvenance;
use App\Services\System\ProcessReniceRunner;
use App\Services\System\ProcessService;
use App\Services\System\ProcessSignalSender;
use App\Services\System\TestProcessIdentityReader;
use App\Services\System\TestProcessLauncher;
use App\Services\System\TestProcessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ManagedProcessSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(ApplicationProcessUid::class, function (MockInterface $mock) {
            $mock->shouldReceive('effectiveUid')->andReturn(1000);
        });
        $this->mock(ProcessSignalSender::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('send');
        });
        $this->mock(ProcessReniceRunner::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('run');
        });
    }

    #[DataProvider('tamperedRecords')]
    public function test_tampered_persisted_records_cannot_receive_signals_or_renice(string $action, array $changes): void
    {
        $process = $this->legitimateRecord();
        ManagedProcess::query()->whereKey($process->id)->update($changes);

        $response = $this->post(route('processes.test.'.$action, $process), $this->parameters($action));

        $response->assertRedirect(route('processes.index'));
        $response->assertSessionHas('test_process_error', 'No se pudo validar la identidad del proceso.');
        $this->assertSame('identity_mismatch', $process->fresh()->status);
    }

    public static function tamperedRecords(): array
    {
        $cases = [];
        foreach (['signal', 'priority'] as $action) {
            foreach ([
                'type bash' => ['process_type' => 'bash'],
                'type apache' => ['process_type' => 'apache'],
                'type custom' => ['process_type' => 'custom'],
                'command' => ['command_label' => '/bin/bash'],
                'pid zero' => ['pid' => 0],
                'pid text hidden by cast' => ['pid' => '42evil'],
                'uid negative' => ['owner_uid' => -1],
                'uid changed' => ['owner_uid' => 1001],
                'ticks zero' => ['start_time_ticks' => 0],
                'ticks changed' => ['start_time_ticks' => 67890],
                'status unknown' => ['status' => 'custom'],
                'status edited' => ['status' => 'stopped'],
                'signature missing' => ['registration_signature' => null],
                'signature forged' => ['registration_signature' => str_repeat('a', 64)],
            ] as $name => $changes) {
                $cases[$action.' '.$name] = [$action, $changes];
            }
        }

        return $cases;
    }

    #[DataProvider('actions')]
    public function test_an_apparently_valid_manual_row_has_no_provenance_and_is_rejected(string $action): void
    {
        $process = $this->manualRecord();
        $this->mock(TestProcessIdentityReader::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('read');
        });

        $this->post(route('processes.test.'.$action, $process), $this->parameters($action))
            ->assertRedirect(route('processes.index'))->assertSessionHas('test_process_error');

        $this->assertNull($process->fresh()->getAttribute('registration_signature'));
    }

    #[DataProvider('actions')]
    public function test_copying_a_legitimate_signature_to_a_different_row_does_not_authorize_it(string $action): void
    {
        $original = $this->legitimateRecord();
        $copy = $this->manualRecord(43);
        $copy->forceFill(['registration_signature' => $original->getAttribute('registration_signature')])->save();

        $this->post(route('processes.test.'.$action, $copy), $this->parameters($action))
            ->assertRedirect(route('processes.index'))->assertSessionHas('test_process_error');
    }

    #[DataProvider('actions')]
    public function test_http_identity_fields_cannot_select_another_process(string $action): void
    {
        $process = $this->manualRecord();
        $this->mock(TestProcessIdentityReader::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('read');
        });

        $this->post(route('processes.test.'.$action, $process), [
            ...$this->parameters($action), 'pid' => 999, 'owner_uid' => 0,
            'start_time_ticks' => 99999, 'process_type' => 'sleep',
            'command_label' => '/usr/bin/sleep 300', 'registration_signature' => str_repeat('a', 64),
        ])->assertRedirect(route('processes.index'))->assertSessionHas('test_process_error');
        $this->assertSame(42, $process->fresh()->pid);
        $this->assertNull($process->fresh()->getAttribute('registration_signature'));
    }

    public static function actions(): array
    {
        return [['signal'], ['priority']];
    }

    public function test_only_the_managed_section_contains_administrative_controls(): void
    {
        $valid = $this->legitimateRecord();
        $manual = $this->manualRecord(43);
        $this->partialMock(ProcessService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getProcesses')->once()->andReturn([[
                'pid' => 999, 'ppid' => 0, 'user' => 'general-user', 'state' => 'S', 'nice' => 0,
                'cpu_percent' => 0.0, 'memory_percent' => 0.0, 'memory_kb' => 100, 'command' => 'general-process',
            ]]);
        });

        $response = $this->get('/procesos');

        $response->assertOk();
        $this->assertTrue(app(ManagedProcessProvenance::class)->verify($valid));
        $this->assertArrayNotHasKey('registration_signature', $valid->toArray());
        $response->assertDontSee($valid->getAttribute('registration_signature'), false);
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(0, $xpath->query('//table[not(@id)]//form | //table[not(@id)]//button | //table[not(@id)]//input')->length);
        $this->assertSame(4, $xpath->query('//table[@id="managed-process-table"]//form[@class="signal-form"]//button[not(@disabled)]')->length);
        $this->assertSame(1, $xpath->query('//table[@id="managed-process-table"]//form[@class="priority-form"]//button[not(@disabled)]')->length);
        $response->assertViewHas('managedProcessActions', [$manual->id => false, $valid->id => true]);
    }

    public function test_registration_signs_only_after_verified_launch_and_cannot_be_mass_assigned(): void
    {
        $process = $this->legitimateRecord();

        $this->assertTrue(app(ManagedProcessProvenance::class)->verify($process->fresh()));
        $this->assertFalse($process->isFillable('registration_signature'));
    }

    public function test_malformed_persisted_text_keeps_the_page_usable_and_disables_actions(): void
    {
        $process = $this->legitimateRecord();
        ManagedProcess::query()->whereKey($process->id)->update(['launched_at' => "invalid\xFF"]);
        $this->partialMock(ProcessService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getProcesses')->once()->andReturn([]);
        });

        $response = $this->get('/procesos');

        $response->assertOk();
        $response->assertViewHas('managedProcessActions', [$process->id => false]);
        $response->assertDontSeeText('JsonException');
        $response->assertDontSeeText('Malformed UTF-8');
    }

    public function test_legitimate_signal_transitions_preserve_registration_integrity(): void
    {
        $process = $this->legitimateRecord();
        $this->mock(TestProcessIdentityReader::class, function (MockInterface $mock) {
            $mock->shouldReceive('read')->with(42)->twice()->andReturn([
                'pid' => 42, 'owner_uid' => 1000, 'start_time_ticks' => 12345,
            ]);
        });
        $this->mock(ProcessSignalSender::class, function (MockInterface $mock) {
            $mock->shouldReceive('send')->with(42, 19)->once()->andReturn(true);
            $mock->shouldReceive('send')->with(42, 18)->once()->andReturn(true);
        });

        foreach (['stop' => 'stopped', 'cont' => 'running'] as $signal => $status) {
            $this->post(route('processes.test.signal', $process), ['signal' => $signal])
                ->assertRedirect(route('processes.index'))->assertSessionHas('test_process_success');
            $current = $process->fresh();
            $this->assertSame($status, $current->status);
            $this->assertTrue(app(ManagedProcessProvenance::class)->verify($current));
        }
    }

    private function legitimateRecord(): ManagedProcess
    {
        $this->mock(TestProcessLauncher::class, function (MockInterface $mock) {
            $mock->shouldReceive('launch')->once()->andReturn('42');
        });
        $this->mock(TestProcessIdentityReader::class, function (MockInterface $mock) {
            $mock->shouldReceive('read')->with(42)->once()->andReturn([
                'pid' => 42, 'owner_uid' => 1000, 'start_time_ticks' => 12345,
            ]);
        });

        return app(TestProcessService::class)->launch();
    }

    private function parameters(string $action): array
    {
        return $action === 'signal' ? ['signal' => 'kill'] : ['nice' => 10];
    }

    private function manualRecord(int $pid = 42): ManagedProcess
    {
        return ManagedProcess::create([
            'pid' => $pid, 'owner_uid' => 1000, 'start_time_ticks' => 12345,
            'process_type' => 'sleep', 'command_label' => '/usr/bin/sleep 300', 'status' => 'running', 'launched_at' => now(),
        ]);
    }
}
