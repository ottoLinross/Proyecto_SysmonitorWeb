<?php

namespace Tests\Unit;

use App\Services\Scheduling\SchedulingMetrics;
use App\Services\Scheduling\SchedulingProcess;
use App\Services\Scheduling\SchedulingSimulation;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SchedulingMetricsTest extends TestCase
{
    #[DataProvider('algorithms')]
    public function test_shared_metrics_for_all_five_algorithms(string $algorithm, array $expected, int $waiting, int $turnaround): void
    {
        $processes = [new SchedulingProcess('A', 0, 5, 5), new SchedulingProcess('B', 1, 2, 1), new SchedulingProcess('C', 2, 1, 3)];
        $result = (new SchedulingSimulation(new SchedulingMetrics))->run($processes, $algorithm, 2);
        $metrics = $result['metrics'];

        $this->assertSame($expected, $metrics['processes']);
        $this->assertSame($waiting, $metrics['total_waiting_time']);
        $this->assertSame($turnaround, $metrics['total_turnaround_time']);
        $this->assertSame(3, $metrics['process_count']);
        $this->assertSame($waiting / 3, $metrics['average_waiting_time']);
        $this->assertSame($turnaround / 3, $metrics['average_turnaround_time']);
        $executed = ['A' => 0, 'B' => 0, 'C' => 0];
        foreach ($result['segments'] as $segment) {
            if ($segment['type'] === 'process') {
                $executed[$segment['process_id']] += $segment['end_time'] - $segment['start_time'];
            }
        }
        $this->assertSame(['A' => 5, 'B' => 2, 'C' => 1], $executed);
    }

    public static function algorithms(): array
    {
        return [
            'FCFS' => ['fcfs', [self::metric('A', 5, 5, 0), self::metric('B', 7, 6, 4), self::metric('C', 8, 6, 5)], 9, 17],
            'SJF' => ['sjf', [self::metric('A', 5, 5, 0), self::metric('B', 8, 7, 5), self::metric('C', 6, 4, 3)], 8, 16],
            'SRTF preemption and resumption' => ['srtf', [self::metric('A', 8, 8, 3), self::metric('B', 3, 2, 0), self::metric('C', 4, 2, 1)], 4, 12],
            'Round Robin multiple slices' => ['round_robin', [self::metric('A', 8, 8, 3), self::metric('B', 4, 3, 1), self::metric('C', 5, 3, 2)], 6, 14],
            'Priorities' => ['priority', [self::metric('A', 5, 5, 0), self::metric('B', 7, 6, 4), self::metric('C', 8, 6, 5)], 9, 17],
        ];
    }

    public function test_idle_is_not_counted_as_waiting_before_arrival(): void
    {
        foreach (array_keys(SchedulingSimulation::ALGORITHMS) as $algorithm) {
            $result = (new SchedulingSimulation(new SchedulingMetrics))->run([
                new SchedulingProcess('A', 3, 2, 1), new SchedulingProcess('B', 10, 1, 1),
            ], $algorithm, 1);
            $this->assertSame([self::metric('A', 5, 2, 0), self::metric('B', 11, 1, 0)], $result['metrics']['processes']);
            $this->assertEquals(0, $result['metrics']['average_waiting_time']);
            $this->assertSame(1.5, $result['metrics']['average_turnaround_time']);
            $this->assertSame(['idle', 'process', 'idle', 'process'], array_column($result['segments'], 'type'));
        }
    }

    public function test_empty_timeline_has_zero_averages_without_division_by_zero(): void
    {
        $metrics = (new SchedulingMetrics)->calculate([], []);
        $this->assertSame([], $metrics['processes']);
        $this->assertSame(0, $metrics['process_count']);
        $this->assertSame(0, $metrics['average_waiting_time']);
        $this->assertSame(0, $metrics['average_turnaround_time']);
    }

    #[DataProvider('invalidTimelines')]
    public function test_inconsistent_execution_is_rejected_instead_of_returning_negative_or_inexact_metrics(array $segments): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new SchedulingMetrics)->calculate([new SchedulingProcess('A', 1, 2, 1)], $segments);
    }

    public static function invalidTimelines(): array
    {
        $idle = ['type' => 'idle', 'process_id' => null, 'start_time' => 0, 'end_time' => 1];
        $run = ['type' => 'process', 'process_id' => 'A', 'start_time' => 1, 'end_time' => 3];

        return [
            'missing burst' => [[]],
            'burst too short' => [[$idle, array_replace($run, ['end_time' => 2])]],
            'burst too long' => [[$idle, array_replace($run, ['end_time' => 4])]],
            'before arrival' => [[array_replace($run, ['start_time' => 0, 'end_time' => 2])]],
            'negative start' => [[array_replace($run, ['start_time' => -1])]],
            'zero duration' => [[$idle, array_replace($run, ['end_time' => 1])]],
            'gap' => [[$run]],
            'overlap' => [[$idle, array_replace($run, ['start_time' => 0])]],
            'unknown process' => [[$idle, array_replace($run, ['process_id' => 'Unknown'])]],
            'idle with process id' => [[array_replace($idle, ['process_id' => 'A']), $run]],
            'noninteger time' => [[$idle, array_replace($run, ['end_time' => 3.0])]],
        ];
    }

    public function test_simulation_rejects_unknown_algorithm(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new SchedulingSimulation(new SchedulingMetrics))->run([], 'unknown');
    }

    private static function metric(string $id, int $completion, int $turnaround, int $waiting): array
    {
        return ['id' => $id, 'completion_time' => $completion, 'turnaround_time' => $turnaround, 'waiting_time' => $waiting];
    }
}
