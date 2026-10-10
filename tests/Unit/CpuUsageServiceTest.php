<?php

namespace Tests\Unit;

use App\Services\System\CpuUsageService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CpuUsageServiceTest extends TestCase
{
    public function test_it_parses_only_the_global_cpu_line_and_ignores_guest_counters(): void
    {
        $snapshot = (new CpuUsageService)->parseSnapshot("cpu  100 20 30 400 50 6 7 8 90 10\ncpu0 1 2 3 4 5 6 7 8\n");

        $this->assertSame([
            'user' => 100, 'nice' => 20, 'system' => 30, 'idle' => 400,
            'iowait' => 50, 'irq' => 6, 'softirq' => 7, 'steal' => 8,
        ], $snapshot);
    }

    #[DataProvider('invalidContents')]
    public function test_invalid_content_is_unavailable(string $content): void
    {
        $this->assertNull((new CpuUsageService)->parseSnapshot($content));
    }

    public static function invalidContents(): array
    {
        return [
            'empty' => [''],
            'whitespace' => [" \t\n"],
            'incomplete' => ['cpu 1 2 3 4 5 6 7'],
            'per cpu only' => ['cpu0 1 2 3 4 5 6 7 8'],
            'global cpu on later line' => ["intr 1\ncpu 1 2 3 4 5 6 7 8"],
            'corrupt' => ['cpu 1 2 invalid 4 5 6 7 8'],
            'negative' => ['cpu 1 2 3 -4 5 6 7 8'],
            'decimal' => ['cpu 1.5 2 3 4 5 6 7 8'],
            'exponent' => ['cpu 1e3 2 3 4 5 6 7 8'],
            'overflow' => ['cpu '.str_repeat('9', 40).' 2 3 4 5 6 7 8'],
        ];
    }

    #[DataProvider('usageCases')]
    public function test_it_calculates_usage_from_deltas(array $deltas, ?float $expected): void
    {
        $previous = array_fill_keys(['user', 'nice', 'system', 'idle', 'iowait', 'irq', 'softirq', 'steal'], 100);
        $current = $previous;
        foreach ($deltas as $field => $delta) {
            $current[$field] += $delta;
        }

        $result = (new CpuUsageService)->calculateUsage($previous, $current);

        if ($expected === null) {
            $this->assertNull($result);
        } else {
            $this->assertNotNull($result);
            $this->assertEqualsWithDelta($expected, $result, 0.000001);
            $this->assertGreaterThanOrEqual(0.0, $result);
            $this->assertLessThanOrEqual(100.0, $result);
        }
    }

    public static function usageCases(): array
    {
        return [
            'normal load all fields' => [['user' => 10, 'nice' => 5, 'system' => 5, 'idle' => 60, 'iowait' => 10, 'irq' => 3, 'softirq' => 4, 'steal' => 3], 30.0],
            'very busy' => [['user' => 98, 'system' => 1, 'idle' => 1], 99.0],
            'almost idle' => [['user' => 1, 'idle' => 999], 0.1],
            'iowait counts as inactive' => [['user' => 10, 'idle' => 20, 'iowait' => 70], 10.0],
            'only iowait' => [['iowait' => 100], 0.0],
            'zero delta' => [[], null],
            'lower limit' => [['idle' => 100], 0.0],
            'upper limit' => [['user' => 100], 100.0],
            'fractional result' => [['user' => 1, 'idle' => 2], 100 / 3],
            'counter regression' => [['user' => -1, 'idle' => 100], null],
            'iowait regression' => [['user' => 100, 'iowait' => -1], null],
        ];
    }

    #[DataProvider('invalidSnapshots')]
    public function test_invalid_snapshots_are_rejected(array $changes): void
    {
        $valid = array_fill_keys(['user', 'nice', 'system', 'idle', 'iowait', 'irq', 'softirq', 'steal'], 100);
        $invalid = array_replace($valid, $changes);
        $service = new CpuUsageService;

        $this->assertNull($service->calculateUsage($invalid, $valid));
        $this->assertNull($service->calculateUsage($valid, $invalid));
        $this->assertNull($service->calculateUsage([], $valid));
        $this->assertNull($service->calculateUsage($valid, []));
    }

    public static function invalidSnapshots(): array
    {
        return [
            'missing counter' => [['user' => null]],
            'negative' => [['user' => -1]],
            'text' => [['user' => 'invalid']],
            'numeric string' => [['user' => '100']],
            'float' => [['user' => 100.0]],
            'infinity' => [['user' => INF]],
            'not a number' => [['user' => NAN]],
        ];
    }

    public function test_small_deltas_on_large_counters_keep_precision(): void
    {
        $service = new CpuUsageService;
        $previous = array_fill_keys(['user', 'nice', 'system', 'idle', 'iowait', 'irq', 'softirq', 'steal'], PHP_INT_MAX - 2);
        $current = $previous;
        $current['user']++;
        $current['idle']++;

        $this->assertSame(50.0, $service->calculateUsage($previous, $current));
    }

    public function test_summing_large_deltas_does_not_overflow(): void
    {
        $fields = ['user', 'nice', 'system', 'idle', 'iowait', 'irq', 'softirq', 'steal'];
        $previous = array_fill_keys($fields, 0);
        $current = array_fill_keys($fields, PHP_INT_MAX);

        $this->assertSame(75.0, (new CpuUsageService)->calculateUsage($previous, $current));
    }

    public function test_sampling_uses_two_controlled_readings_without_sleeping(): void
    {
        $service = $this->getMockBuilder(CpuUsageService::class)->onlyMethods(['readStat', 'waitForSample'])->getMock();
        $service->expects($this->exactly(2))->method('readStat')->willReturnOnConsecutiveCalls(
            'cpu 100 0 0 100 0 0 0 0 0 0',
            'cpu 130 0 0 170 0 0 0 0 30 0',
        );
        $service->expects($this->once())->method('waitForSample');

        $this->assertSame(30.0, $service->getUsagePercent());
    }

    public function test_failed_first_read_does_not_wait_or_read_again(): void
    {
        $service = $this->getMockBuilder(CpuUsageService::class)->onlyMethods(['readStat', 'waitForSample'])->getMock();
        $service->expects($this->once())->method('readStat')->willReturn(null);
        $service->expects($this->never())->method('waitForSample');

        $this->assertNull($service->getUsagePercent());
    }

    public function test_failed_second_read_is_unavailable(): void
    {
        $service = $this->getMockBuilder(CpuUsageService::class)->onlyMethods(['readStat', 'waitForSample'])->getMock();
        $service->expects($this->exactly(2))->method('readStat')->willReturnOnConsecutiveCalls('cpu 1 2 3 4 5 6 7 8', null);
        $service->expects($this->once())->method('waitForSample');

        $this->assertNull($service->getUsagePercent());
    }
}
