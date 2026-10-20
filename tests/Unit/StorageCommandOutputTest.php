<?php

namespace Tests\Unit;

use App\Services\Storage\StorageCommandOutput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class StorageCommandOutputTest extends TestCase
{
    public function test_stdout_is_returned_and_stderr_is_discarded(): void
    {
        $process = new Process([PHP_BINARY, '-r', 'fwrite(STDERR, "private diagnostic"); echo "safe output";'], null, null, null, 5);
        $this->assertSame('safe output', StorageCommandOutput::run($process));
        $this->assertSame('', $process->getOutput());
        $this->assertSame('', $process->getErrorOutput());
        $this->assertFalse($process->isRunning());
    }

    public function test_nonzero_exit_cannot_return_partial_success(): void
    {
        $process = new Process([PHP_BINARY, '-r', 'echo "partial"; fwrite(STDERR, base64_decode("c2VjcmV0")); exit(2);'], null, null, null, 5);
        try {
            StorageCommandOutput::run($process);
            $this->fail('Nonzero exit must fail');
        } catch (ProcessFailedException $exception) {
            $this->assertSame(2, $exception->getProcess()->getExitCode());
            $this->assertStringNotContainsString('secret', $exception->getMessage());
        }
        $this->assertSame('', $process->getErrorOutput());
    }

    public static function streams(): array
    {
        return [['STDOUT'], ['STDERR']];
    }

    #[DataProvider('streams')]
    public function test_excessive_output_terminates_the_process_and_clears_buffers(string $stream): void
    {
        $process = new Process([PHP_BINARY, '-r', 'while (true) { fwrite('.$stream.', str_repeat("x", 8192)); }'], null, null, null, 5);
        try {
            StorageCommandOutput::run($process);
            $this->fail('Oversized output must fail');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Storage output limit exceeded', $exception->getMessage());
        }
        $this->assertFalse($process->isRunning());
        $this->assertSame('', $process->getOutput());
        $this->assertSame('', $process->getErrorOutput());
    }

    public function test_combined_stdout_and_stderr_limit_terminates_the_process(): void
    {
        // Each stream emits 576 KiB; together they exceed the 1 MiB limit.
        $process = new Process([PHP_BINARY, '-r', 'for ($i = 0; $i < 72; $i++) { fwrite(STDOUT, str_repeat("x", 8192)); fwrite(STDERR, str_repeat("y", 8192)); } usleep(2000000);'], null, null, null, 5);
        try {
            StorageCommandOutput::run($process);
            $this->fail('Combined oversized output must fail');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Storage output limit exceeded', $exception->getMessage());
        }
        $this->assertFalse($process->isRunning());
        $this->assertTrue($process->isTerminated());
        $this->assertSame('', $process->getOutput());
        $this->assertSame('', $process->getErrorOutput());
    }

    public function test_timeout_stops_process_and_clears_buffers(): void
    {
        $process = new Process([PHP_BINARY, '-r', 'usleep(500000);'], null, null, null, 0.05);
        try {
            StorageCommandOutput::run($process);
            $this->fail('Timeout must fail');
        } catch (ProcessTimedOutException) {
            $this->assertFalse($process->isRunning());
        }
        $this->assertSame('', $process->getOutput());
    }

    public function test_exact_output_limit_is_accepted(): void
    {
        $process = new Process([PHP_BINARY, '-r', 'echo str_repeat("x", 1048576);'], null, null, null, 5);
        $this->assertSame(StorageCommandOutput::MAX_BYTES, strlen(StorageCommandOutput::run($process)));
    }
}
