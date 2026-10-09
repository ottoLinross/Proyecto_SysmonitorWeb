<?php

namespace Tests\Unit;

use App\Models\ManagedProcess;
use App\Services\System\ManagedProcessIdentityGuard;
use App\Services\System\ProcessSignalSender;
use App\Services\System\ProcessSignalService;
use App\Services\System\TestProcessIdentityReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ProcessSignalServiceTest extends TestCase
{
    #[DataProvider('allowedSignals')]
    public function test_valid_identity_allows_only_the_expected_signal_and_updates_status(string $signal, int $number, string $status): void
    {
        $process = $this->process($signal === 'cont' ? 'stopped' : 'running');
        $reader = $this->createMock(TestProcessIdentityReader::class);
        $reader->expects($this->once())->method('read')->with(42)->willReturn($this->identity());
        $reader->method('hasExited')->with(42, 12345)->willReturn(true);
        $sender = $this->createMock(ProcessSignalSender::class);
        $sender->expects($this->once())->method('send')->with(42, $number)->willReturn(true);

        $result = $this->service($reader, $sender)->send($process, $signal);

        $this->assertTrue($result['success']);
        $this->assertSame($status, $process->status);
        $this->assertSame(1, $process->saves);
        $this->assertSame(7, $result['managed_process_id']);
        $this->assertSame(42, $result['pid']);
        $this->assertSame($signal, $result['signal']);
        $this->assertNotEmpty($result['occurred_at']);
    }

    public static function allowedSignals(): array
    {
        return [
            'term' => ['term', 15, 'terminated'], 'kill' => ['kill', 9, 'killed'],
            'stop' => ['stop', 19, 'stopped'], 'cont' => ['cont', 18, 'running'],
        ];
    }

    #[DataProvider('invalidSignals')]
    public function test_unknown_signals_never_read_or_send(string $signal): void
    {
        $reader = $this->createMock(TestProcessIdentityReader::class);
        $reader->expects($this->never())->method('read');
        $sender = $this->createMock(ProcessSignalSender::class);
        $sender->expects($this->never())->method('send');

        $result = $this->service($reader, $sender)->send($this->process(), $signal);

        $this->assertFalse($result['success']);
        $this->assertSame('invalid_signal', $result['outcome']);
    }

    public static function invalidSignals(): array
    {
        return [['9'], ['USR1'], ['HUP'], ['ALL'], ['TERM'], [''], ['stop; arbitrary-command']];
    }

    #[DataProvider('mismatchedIdentities')]
    public function test_mismatched_identity_never_receives_a_signal(array $identity): void
    {
        $process = $this->process();
        $reader = $this->createMock(TestProcessIdentityReader::class);
        $reader->expects($this->once())->method('read')->with(42)->willReturn($identity);
        $sender = $this->createMock(ProcessSignalSender::class);
        $sender->expects($this->never())->method('send');

        $result = $this->service($reader, $sender)->send($process, 'kill');

        $this->assertFalse($result['success']);
        $this->assertSame('identity_mismatch', $process->status);
    }

    public static function mismatchedIdentities(): array
    {
        return [
            'PID' => [['pid' => 43, 'owner_uid' => 1000, 'start_time_ticks' => 12345]],
            'owner' => [['pid' => 42, 'owner_uid' => 1001, 'start_time_ticks' => 12345]],
            'reused PID' => [['pid' => 42, 'owner_uid' => 1000, 'start_time_ticks' => 67890]],
            'invalid identity' => [[]],
        ];
    }

    #[DataProvider('failedReads')]
    public function test_failed_identity_reads_never_send_and_update_status(bool $exists, string $expected): void
    {
        $process = $this->process();
        $reader = $this->createMock(TestProcessIdentityReader::class);
        $reader->method('read')->willThrowException(new RuntimeException('internal detail'));
        $reader->method('exists')->with(42)->willReturn($exists);
        $sender = $this->createMock(ProcessSignalSender::class);
        $sender->expects($this->never())->method('send');

        $result = $this->service($reader, $sender)->send($process, 'stop');

        $this->assertFalse($result['success']);
        $this->assertSame($expected, $process->status);
        $this->assertStringNotContainsString('internal detail', $result['message']);
    }

    public static function failedReads(): array
    {
        return ['missing' => [false, 'missing'], 'unverifiable' => [true, 'identity_mismatch']];
    }

    #[DataProvider('inactiveStatuses')]
    public function test_terminal_records_are_revalidated_but_cannot_receive_new_signals(string $status): void
    {
        $process = $this->process($status);
        $reader = $this->createMock(TestProcessIdentityReader::class);
        $reader->expects($this->once())->method('read')->willReturn($this->identity());
        $sender = $this->createMock(ProcessSignalSender::class);
        $sender->expects($this->never())->method('send');

        $result = $this->service($reader, $sender)->send($process, 'kill');

        $this->assertSame('inactive', $result['outcome']);
        $this->assertSame($status, $process->status);
    }

    public static function inactiveStatuses(): array
    {
        return [['terminated'], ['killed'], ['missing'], ['identity_mismatch']];
    }

    public function test_an_unsaved_record_cannot_receive_signals(): void
    {
        $process = $this->process();
        $process->exists = false;
        $reader = $this->createMock(TestProcessIdentityReader::class);
        $reader->expects($this->never())->method('read');
        $sender = $this->createMock(ProcessSignalSender::class);
        $sender->expects($this->never())->method('send');

        $this->assertSame('invalid_record', $this->service($reader, $sender)->send($process, 'term')['outcome']);
    }

    public function test_a_zombie_of_the_same_identity_is_already_inactive_and_never_signalled(): void
    {
        $process = $this->process();
        $reader = $this->createMock(TestProcessIdentityReader::class);
        $reader->method('read')->willThrowException(new RuntimeException);
        $reader->method('exists')->willReturn(true);
        $reader->expects($this->once())->method('hasExited')->with(42, 12345)->willReturn(true);
        $sender = $this->createMock(ProcessSignalSender::class);
        $sender->expects($this->never())->method('send');

        $this->service($reader, $sender)->send($process, 'kill');

        $this->assertSame('missing', $process->status);
    }

    public function test_failed_delivery_does_not_claim_a_state_change(): void
    {
        $process = $this->process();
        $reader = $this->createMock(TestProcessIdentityReader::class);
        $reader->method('read')->willReturn($this->identity());
        $sender = $this->createMock(ProcessSignalSender::class);
        $sender->expects($this->once())->method('send')->willReturn(false);

        $result = $this->service($reader, $sender)->send($process, 'stop');

        $this->assertSame('send_failed', $result['outcome']);
        $this->assertSame('running', $process->status);
        $this->assertSame(0, $process->saves);
    }

    public function test_termination_wait_is_bounded_and_does_not_claim_disappearance(): void
    {
        $process = $this->process('stopped');
        $reader = $this->createMock(TestProcessIdentityReader::class);
        $reader->expects($this->exactly(11))->method('read')->willReturn($this->identity());
        $reader->expects($this->exactly(10))->method('hasExited')->willReturn(false);
        $sender = $this->createMock(ProcessSignalSender::class);
        $sender->expects($this->once())->method('send')->with(42, 15)->willReturn(true);

        $result = $this->service($reader, $sender)->send($process, 'term');

        $this->assertTrue($result['success']);
        $this->assertSame('pending', $result['outcome']);
        $this->assertSame('stopped', $process->status);
    }

    public function test_pid_reuse_after_delivery_does_not_target_the_new_process(): void
    {
        $process = $this->process();
        $reader = $this->createMock(TestProcessIdentityReader::class);
        $reader->expects($this->exactly(2))->method('read')->willReturnOnConsecutiveCalls(
            $this->identity(), ['pid' => 42, 'owner_uid' => 1000, 'start_time_ticks' => 67890],
        );
        $reader->method('hasExited')->willReturn(false);
        $sender = $this->createMock(ProcessSignalSender::class);
        $sender->expects($this->once())->method('send')->with(42, 9)->willReturn(true);

        $this->service($reader, $sender)->send($process, 'kill');

        $this->assertSame('identity_mismatch', $process->status);
    }

    private function identity(): array
    {
        return ['pid' => 42, 'owner_uid' => 1000, 'start_time_ticks' => 12345];
    }

    private function process(string $status = 'running'): ManagedProcess
    {
        $process = new class extends ManagedProcess
        {
            public int $saves = 0;

            public function save(array $options = []): bool
            {
                $this->saves++;

                return true;
            }
        };
        $process->forceFill([
            ...$this->identity(), 'id' => 7, 'process_type' => 'sleep',
            'command_label' => '/usr/bin/sleep 300', 'status' => $status,
        ]);
        $process->exists = true;

        return $process;
    }

    private function service(TestProcessIdentityReader $reader, ProcessSignalSender $sender): ProcessSignalService
    {
        return new class($reader, $sender, new ManagedProcessIdentityGuard($reader)) extends ProcessSignalService
        {
            protected function pause(): void {}
        };
    }
}
