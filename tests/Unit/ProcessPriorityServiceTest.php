<?php

namespace Tests\Unit;

use App\Models\ManagedProcess;
use App\Services\System\ProcessPriorityService;
use App\Services\System\ProcessReniceRunner;
use App\Services\System\TestProcessIdentityReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\ManagedProcessFixtures;

class ProcessPriorityServiceTest extends TestCase
{
    use ManagedProcessFixtures;

    #[DataProvider('validNiceValues')]
    public function test_valid_nice_is_applied_and_verified(mixed $value, int $expected): void
    {
        $reader = $this->createMock(TestProcessIdentityReader::class);
        $reader->expects($this->once())->method('read')->with(42)->willReturn($this->identity());
        $reader->expects($this->once())->method('readWithNice')->with(42)->willReturn([...$this->identity(), 'nice' => $expected]);
        $runner = $this->createMock(ProcessReniceRunner::class);
        $runner->expects($this->once())->method('run')->with(42, $expected)->willReturn($this->execution());
        $process = $this->process('stopped');

        $result = $this->service($reader, $runner)->change($process, $value);

        $this->assertTrue($result['success']);
        $this->assertSame($expected, $result['nice']);
        $this->assertSame('confirmed', $result['outcome']);
        $this->assertSame('stopped', $process->status);
    }

    public static function validNiceValues(): array
    {
        return [[-20, -20], [19, 19], ['-20', -20], ['19', 19], ['0', 0], [10, 10]];
    }

    #[DataProvider('invalidNiceValues')]
    public function test_invalid_nice_never_reads_or_runs(mixed $value): void
    {
        $reader = $this->createMock(TestProcessIdentityReader::class);
        $reader->expects($this->never())->method('read');
        $runner = $this->createMock(ProcessReniceRunner::class);
        $runner->expects($this->never())->method('run');

        $result = $this->service($reader, $runner)->change($this->process(), $value);

        $this->assertFalse($result['success']);
        $this->assertSame('invalid_nice', $result['outcome']);
    }

    public static function invalidNiceValues(): array
    {
        return [[-21], [20], ['text'], [10.5], [10.0], ['10.0'], ['1e1'], ['10; other-command'],
            ['+10'], [' 10 '], ['9999999999999999999999'], [['10']], [null], [true]];
    }

    #[DataProvider('identityChanges')]
    public function test_identity_changes_reject_before_or_after_renice(array $identity, bool $after): void
    {
        $process = $this->process();
        $reader = $this->createMock(TestProcessIdentityReader::class);
        $reader->expects($this->once())->method('read')->willReturn($after ? $this->identity() : $identity);
        $runner = $this->createMock(ProcessReniceRunner::class);
        if ($after) {
            $runner->expects($this->once())->method('run')->willReturn($this->execution());
            $reader->expects($this->once())->method('readWithNice')->willReturn([...$identity, 'nice' => 10]);
        } else {
            $runner->expects($this->never())->method('run');
        }

        $result = $this->service($reader, $runner)->change($process, 10);

        $this->assertFalse($result['success']);
        $this->assertSame('identity_mismatch', $process->status);
    }

    public static function identityChanges(): array
    {
        $cases = [];
        foreach ([
            'PID' => ['pid' => 43, 'owner_uid' => 1000, 'start_time_ticks' => 12345],
            'UID' => ['pid' => 42, 'owner_uid' => 1001, 'start_time_ticks' => 12345],
            'reused PID' => ['pid' => 42, 'owner_uid' => 1000, 'start_time_ticks' => 67890],
        ] as $name => $identity) {
            $cases[$name.' before'] = [$identity, false];
            $cases[$name.' after'] = [$identity, true];
        }

        return $cases;
    }

    public function test_missing_process_prevents_execution(): void
    {
        $process = $this->process();
        $reader = $this->createMock(TestProcessIdentityReader::class);
        $reader->method('read')->willThrowException(new RuntimeException);
        $reader->method('exists')->willReturn(false);
        $runner = $this->createMock(ProcessReniceRunner::class);
        $runner->expects($this->never())->method('run');

        $result = $this->service($reader, $runner)->change($process, 10);

        $this->assertSame('missing', $process->status);
        $this->assertSame('El proceso ya no existe.', $result['message']);
    }

    public function test_terminal_and_unsaved_records_cannot_run_renice(): void
    {
        foreach (['terminated', 'killed', 'missing', 'identity_mismatch', 'unsaved'] as $status) {
            $process = $this->process($status);
            $process->exists = $status !== 'unsaved';
            $reader = $this->createMock(TestProcessIdentityReader::class);
            $reader->method('read')->willReturn($this->identity());
            $runner = $this->createMock(ProcessReniceRunner::class);
            $runner->expects($this->never())->method('run');

            $this->assertFalse($this->service($reader, $runner)->change($process, 10)['success']);
        }
    }

    public function test_exit_zero_without_the_expected_nice_is_a_failure(): void
    {
        $reader = $this->createMock(TestProcessIdentityReader::class);
        $reader->method('read')->willReturn($this->identity());
        $reader->method('readWithNice')->willReturn([...$this->identity(), 'nice' => 0]);
        $runner = $this->createMock(ProcessReniceRunner::class);
        $runner->method('run')->willReturn($this->execution());

        $this->assertFalse($this->service($reader, $runner)->change($this->process(), 10)['success']);
    }

    public function test_linux_denial_does_not_expose_stderr(): void
    {
        $reader = $this->createMock(TestProcessIdentityReader::class);
        $reader->method('read')->willReturn($this->identity());
        $reader->expects($this->never())->method('readWithNice');
        $runner = $this->createMock(ProcessReniceRunner::class);
        $runner->method('run')->willReturn(['exit_code' => 1, 'stdout' => 'internal stdout', 'stderr' => 'internal Permission denied']);

        $result = $this->service($reader, $runner)->change($this->process(), -20);

        $this->assertFalse($result['success']);
        $this->assertSame('No fue posible cambiar la prioridad del proceso.', $result['message']);
        $this->assertStringNotContainsString('internal', json_encode($result));
    }

    public function test_fixed_runner_command_is_an_array_and_never_uses_a_shell(): void
    {
        $runner = new class extends ProcessReniceRunner
        {
            public array $commands = [];

            protected function execute(array $command): array
            {
                $this->commands[] = $command;

                return ['exit_code' => 0, 'stdout' => '', 'stderr' => ''];
            }
        };

        $runner->run(42, -20);
        $runner->run(42, 19);
        $this->assertSame([
            ['/usr/bin/renice', '-n', '-20', '-p', '42'],
            ['/usr/bin/renice', '-n', '19', '-p', '42'],
        ], $runner->commands);
        $this->assertSame(-1, $runner->run(42, 20)['exit_code']);
        $this->assertSame(-1, $runner->run(0, 10)['exit_code']);
        $this->assertCount(2, $runner->commands);
    }

    public function test_disappearance_after_renice_is_not_reported_as_success(): void
    {
        $process = $this->process();
        $reader = $this->createMock(TestProcessIdentityReader::class);
        $reader->method('read')->willReturn($this->identity());
        $reader->method('readWithNice')->willThrowException(new RuntimeException);
        $reader->method('exists')->willReturn(false);
        $runner = $this->createMock(ProcessReniceRunner::class);
        $runner->expects($this->once())->method('run')->willReturn($this->execution());

        $result = $this->service($reader, $runner)->change($process, 10);

        $this->assertFalse($result['success']);
        $this->assertSame('missing', $process->status);
    }

    private function identity(): array
    {
        return ['pid' => 42, 'owner_uid' => 1000, 'start_time_ticks' => 12345];
    }

    private function execution(): array
    {
        return ['exit_code' => 0, 'stdout' => '', 'stderr' => ''];
    }

    private function process(string $status = 'running'): ManagedProcess
    {
        $process = new class extends ManagedProcess
        {
            public function save(array $options = []): bool
            {
                return true;
            }
        };
        $process->forceFill([...$this->identity(), 'id' => 7, 'status' => $status,
            'process_type' => 'sleep', 'command_label' => '/usr/bin/sleep 300']);
        $process->exists = true;

        return $this->sealFixture($process);
    }

    private function service(TestProcessIdentityReader $reader, ProcessReniceRunner $runner): ProcessPriorityService
    {
        return new ProcessPriorityService($this->fixtureGuard($reader), $reader, $runner);
    }
}
