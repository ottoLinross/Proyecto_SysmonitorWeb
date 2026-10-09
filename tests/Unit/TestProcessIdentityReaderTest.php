<?php

namespace Tests\Unit;

use App\Services\System\TestProcessIdentityReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class TestProcessIdentityReaderTest extends TestCase
{
    public function test_it_reads_real_uid_and_field_22_with_parentheses_in_the_name(): void
    {
        $files = $this->validFiles();
        $files['/proc/42/stat'] = self::stat('987654', 'S', 'sleep ) worker (name)');

        $this->assertSame(['pid' => 42, 'owner_uid' => 1000, 'start_time_ticks' => 987654], $this->reader($files)->read(42));
    }

    public function test_uid_zero_is_a_valid_identity_when_php_has_the_same_uid(): void
    {
        $files = $this->validFiles();
        $files['/proc/self/status'] = $files['/proc/42/status'] = "Uid:\t0\t0\t0\t0\n";

        $this->assertSame(0, $this->reader($files)->read(42)['owner_uid']);
    }

    #[DataProvider('invalidIdentities')]
    public function test_missing_or_invalid_proc_identity_fails_safely(array $overrides): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No se pudo verificar el proceso de prueba.');

        $this->reader(array_replace($this->validFiles(), $overrides))->read(42);
    }

    public static function invalidIdentities(): array
    {
        return [
            'missing process stat' => [['/proc/42/stat' => null]],
            'unreadable status' => [['/proc/42/status' => null]],
            'unreadable php status' => [['/proc/self/status' => null]],
            'negative UID' => [['/proc/42/status' => "Uid:\t-1\t-1\t-1\t-1\n"]],
            'overflow UID' => [['/proc/42/status' => "Uid:\t999999999999999999999999\t1\t1\t1\n"]],
            'different owner' => [['/proc/42/status' => "Uid:\t1001\t1001\t1001\t1001\n"]],
            'invalid ticks' => [['/proc/42/stat' => self::stat('invalid')]],
            'zero ticks' => [['/proc/42/stat' => self::stat('0')]],
            'negative ticks' => [['/proc/42/stat' => self::stat('-1')]],
            'overflow ticks' => [['/proc/42/stat' => self::stat('999999999999999999999999')]],
            'truncated stat' => [['/proc/42/stat' => '42 (sleep) S 0']],
            'different PID' => [['/proc/42/stat' => str_replace('42 (', '43 (', self::stat('12345'))]],
            'zombie' => [['/proc/42/stat' => self::stat('12345', 'Z')]],
            'different command' => [['/proc/42/cmdline' => "/usr/bin/sleep\0"."600\0"]],
            'unreadable command' => [['/proc/42/cmdline' => null]],
            'PID reused between stat reads' => [['/proc/42/stat' => [self::stat('12345'), self::stat('67890')]]],
        ];
    }

    public function test_it_retries_when_nohup_has_not_yet_become_sleep(): void
    {
        $files = $this->validFiles();
        $files['/proc/42/cmdline'] = ["/usr/bin/nohup\0", "/usr/bin/sleep\0"."300\0"];

        $this->assertSame(12345, $this->reader($files)->read(42)['start_time_ticks']);
    }

    public function test_non_positive_pid_is_rejected_before_reading_proc(): void
    {
        $this->expectException(RuntimeException::class);
        $this->reader([])->read(0);
    }

    public function test_a_stopped_process_retains_a_valid_identity(): void
    {
        $files = $this->validFiles();
        $files['/proc/42/stat'] = self::stat('12345', 'T');

        $this->assertSame(['pid' => 42, 'owner_uid' => 1000, 'start_time_ticks' => 12345], $this->reader($files)->read(42));
    }

    public function test_exited_detection_requires_the_same_starttime(): void
    {
        $files = $this->validFiles();
        $files['/proc/42/stat'] = self::stat('12345', 'Z');
        $reader = $this->reader($files);

        $this->assertTrue($reader->hasExited(42, 12345));
        $this->assertFalse($reader->hasExited(42, 67890));
        $this->assertFalse($reader->hasExited(0, 12345));
    }

    public function test_missing_and_unreadable_stat_are_distinguished_from_confirmed_exit(): void
    {
        $files = $this->validFiles();
        $files['/proc/42/stat'] = null;

        $this->assertFalse($this->reader($files)->hasExited(42, 12345));
        $this->assertFalse($this->reader($this->validFiles())->hasExited(42, 12345));
        $this->assertTrue($this->reader([])->hasExited(42, 12345));
    }

    public function test_nice_is_read_from_field_19_with_complex_comm(): void
    {
        foreach (['-20', '19', '0'] as $nice) {
            $files = $this->validFiles();
            $files['/proc/42/stat'] = self::stat('12345', 'T', 'sleep ) worker (name)', $nice);

            $this->assertSame([...['pid' => 42, 'owner_uid' => 1000, 'start_time_ticks' => 12345], 'nice' => (int) $nice],
                $this->reader($files)->readWithNice(42));
        }
    }

    public function test_invalid_nice_in_stat_is_rejected(): void
    {
        $files = $this->validFiles();
        $files['/proc/42/stat'] = self::stat('12345', 'S', 'sleep', '10.5');
        $this->expectException(RuntimeException::class);

        $this->reader($files)->readWithNice(42);
    }

    public function test_identity_changes_during_nice_read_are_rejected(): void
    {
        $files = $this->validFiles();
        $files['/proc/42/stat'] = [self::stat('12345'), self::stat('12345'), self::stat('67890'), self::stat('67890'), self::stat('67890')];
        $this->expectException(RuntimeException::class);

        $this->reader($files)->readWithNice(42);
    }

    private static function stat(string $ticks, string $state = 'S', string $name = 'sleep', string $nice = '0'): string
    {
        $fields = [$state, ...array_fill(0, 18, '0'), $ticks];
        $fields[16] = $nice;

        return '42 ('.$name.') '.implode(' ', $fields);
    }

    private function validFiles(): array
    {
        return [
            '/proc/self/status' => "Name:\tphp\nUid:\t1000\t1000\t1000\t1000\n",
            '/proc/42/stat' => self::stat('12345'),
            '/proc/42/status' => "Name:\tsleep\nUid:\t1000\t1000\t1000\t1000\n",
            '/proc/42/cmdline' => "/usr/bin/sleep\0"."300\0",
        ];
    }

    private function reader(array $files): TestProcessIdentityReader
    {
        return new class($files) extends TestProcessIdentityReader
        {
            private array $reads = [];

            public function __construct(private readonly array $files) {}

            protected function readFile(string $path): ?string
            {
                $value = $this->files[$path] ?? null;
                if (is_array($value)) {
                    $index = $this->reads[$path] ?? 0;
                    $this->reads[$path] = $index + 1;

                    return $value[$index % count($value)];
                }

                return $value;
            }

            public function exists(int $pid): bool
            {
                return array_key_exists('/proc/'.$pid.'/stat', $this->files);
            }
        };
    }
}
