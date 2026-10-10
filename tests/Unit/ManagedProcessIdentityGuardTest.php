<?php

namespace Tests\Unit;

use App\Models\ManagedProcess;
use App\Services\System\ProcessPriorityService;
use App\Services\System\ProcessReniceRunner;
use App\Services\System\ProcessSignalSender;
use App\Services\System\ProcessSignalService;
use App\Services\System\TestProcessIdentityReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\ManagedProcessFixtures;

class ManagedProcessIdentityGuardTest extends TestCase
{
    use ManagedProcessFixtures;

    #[DataProvider('activeStatuses')]
    public function test_only_recognized_active_records_continue_to_real_identity(string $status): void
    {
        $reader = $this->createMock(TestProcessIdentityReader::class);
        $reader->expects($this->once())->method('read')->with(42)->willReturn($this->identity());

        $this->assertTrue($this->fixtureGuard($reader)->verify($this->record(['status' => $status]))['valid']);
    }

    public static function activeStatuses(): array
    {
        return [['running'], ['stopped']];
    }

    #[DataProvider('invalidRecords')]
    public function test_invalid_raw_records_are_rejected_before_proc_even_with_a_seal(array $attributes): void
    {
        $process = $this->record($attributes);
        $reader = $this->createMock(TestProcessIdentityReader::class);
        $reader->expects($this->never())->method('read');

        $this->assertFalse($this->fixtureGuard($reader)->verify($process)['valid']);
    }

    public static function invalidRecords(): array
    {
        return [
            [['process_type' => 'bash']], [['process_type' => 'apache']], [['process_type' => 'custom']],
            [['command_label' => '/bin/bash']], [['pid' => 0]], [['pid' => -1]], [['pid' => '42evil']],
            [['pid' => 42.5]], [['pid' => 2147483648]], [['owner_uid' => -1]], [['owner_uid' => '1000evil']],
            [['owner_uid' => 1000.5]], [['owner_uid' => null]], [['start_time_ticks' => 0]],
            [['start_time_ticks' => '12345evil']], [['start_time_ticks' => 12345.5]], [['status' => 'custom']],
        ];
    }

    #[DataProvider('uidContexts')]
    public function test_unknown_or_different_php_uid_prevents_actions(?int $uid): void
    {
        $process = $this->record();
        $reader = $this->createMock(TestProcessIdentityReader::class);
        $reader->expects($this->never())->method('read');

        $this->assertFalse($this->fixtureGuard($reader, $uid)->verify($process)['valid']);
        $this->assertSame('identity_mismatch', $process->status);
    }

    public static function uidContexts(): array
    {
        return [[1001], [null]];
    }

    public function test_arbitrary_unsigned_record_never_reaches_either_mechanism(): void
    {
        $process = $this->record();
        $process->setAttribute('registration_signature', null);
        $reader = $this->createMock(TestProcessIdentityReader::class);
        $reader->expects($this->never())->method('read');
        $sender = $this->createMock(ProcessSignalSender::class);
        $sender->expects($this->never())->method('send');
        $runner = $this->createMock(ProcessReniceRunner::class);
        $runner->expects($this->never())->method('run');
        $guard = $this->fixtureGuard($reader);

        $this->assertFalse((new ProcessSignalService($reader, $sender, $guard))->send($process, 'kill')['success']);
        $this->assertFalse((new ProcessPriorityService($guard, $reader, $runner))->change($process, 10)['success']);
        $this->assertNull($process->getAttribute('registration_signature'));
    }

    #[DataProvider('sealedFields')]
    public function test_tampering_any_sealed_field_or_copying_a_row_breaks_provenance(string $field, mixed $value): void
    {
        $process = $this->record();
        $this->assertTrue($this->fixtureProvenance()->verify($process));
        $process->setAttribute($field, $value);

        $this->assertFalse($this->fixtureProvenance()->verify($process));
        $reader = $this->createMock(TestProcessIdentityReader::class);
        $reader->expects($this->never())->method('read');
        $this->assertFalse($this->fixtureGuard($reader)->verify($process)['valid']);
    }

    public static function sealedFields(): array
    {
        return [['id', 8], ['pid', 43], ['owner_uid', 1001], ['start_time_ticks', 67890],
            ['process_type', 'bash'], ['command_label', '/bin/bash'], ['status', 'stopped'],
            ['launched_at', '2026-10-08 11:00:00'], ['registration_signature', str_repeat('a', 64)]];
    }

    public function test_safe_state_transitions_reseal_only_trusted_records(): void
    {
        $process = $this->record();
        $reader = $this->createMock(TestProcessIdentityReader::class);
        $guard = $this->fixtureGuard($reader);
        $guard->updateStatus($process, 'stopped');

        $this->assertTrue($this->fixtureProvenance()->verify($process));
        $guard->updateStatus($process, 'running');
        $this->assertTrue($this->fixtureProvenance()->verify($process));
    }

    public function test_malformed_persisted_text_is_rejected_without_internal_exceptions(): void
    {
        $process = $this->record();
        $process->setRawAttributes([...$process->getAttributes(), 'launched_at' => "invalid\xFF"]);
        $reader = $this->createMock(TestProcessIdentityReader::class);
        $reader->expects($this->never())->method('read');
        $guard = $this->fixtureGuard($reader);

        $this->assertFalse($this->fixtureProvenance()->verify($process));
        $this->assertFalse($guard->canOfferActions($process));
        $this->assertFalse($guard->verify($process)['valid']);
    }

    private function identity(): array
    {
        return ['pid' => 42, 'owner_uid' => 1000, 'start_time_ticks' => 12345];
    }

    private function record(array $overrides = []): ManagedProcess
    {
        $process = new class extends ManagedProcess
        {
            protected $dateFormat = 'Y-m-d H:i:s';

            public function save(array $options = []): bool
            {
                return true;
            }
        };
        $process->forceFill(array_replace([...$this->identity(), 'id' => 7, 'process_type' => 'sleep',
            'command_label' => '/usr/bin/sleep 300', 'status' => 'running', 'launched_at' => '2026-10-08 10:00:00'], $overrides));
        $process->exists = true;

        return $this->sealFixture($process);
    }
}
