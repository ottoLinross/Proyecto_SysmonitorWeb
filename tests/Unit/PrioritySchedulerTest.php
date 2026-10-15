<?php

namespace Tests\Unit;

use App\Services\Scheduling\PriorityScheduler;
use App\Services\Scheduling\SchedulingProcess;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PrioritySchedulerTest extends TestCase
{
    /** Lower numeric values always mean higher priority; execution is non-preemptive. */
    #[DataProvider('schedules')]
    public function test_priority_execution_and_burst_conservation(array $input, array $expected): void
    {
        $processes = array_map(fn (array $p): SchedulingProcess => new SchedulingProcess(...$p), $input);
        $original = $processes;
        $scheduler = new PriorityScheduler;

        $execution = $scheduler->schedule($processes);

        $this->assertSame($expected, $execution);
        $this->assertSame($original, $processes);
        $this->assertSame($execution, $scheduler->schedule($processes));

        $executed = array_fill_keys(array_column($input, 0), 0);
        $time = 0;
        foreach ($execution as $segment) {
            $this->assertSame($time, $segment['start_time']);
            $this->assertGreaterThan($segment['start_time'], $segment['end_time']);
            if ($segment['type'] === 'process') {
                $this->assertArrayHasKey($segment['process_id'], $executed);
                $this->assertSame(0, $executed[$segment['process_id']], 'A process must execute once without preemption.');
                $executed[$segment['process_id']] += $segment['end_time'] - $segment['start_time'];
            }
            $time = $segment['end_time'];
        }
        foreach ($processes as $process) {
            $this->assertSame($process->burstTime, $executed[$process->id]);
        }
        $this->assertSame(array_sum(array_column($input, 2)), array_sum($executed));
    }

    public static function schedules(): array
    {
        return [
            'lower number means higher priority regardless of burst length' => [
                [['Low', 0, 1, 10], ['High', 0, 6, 1], ['Medium', 0, 2, 5]],
                [self::execution('High', 0, 6), self::execution('Medium', 6, 8), self::execution('Low', 8, 9)],
            ],
            'priority ties prefer earlier arrival over original order' => [
                [['Later', 2, 1, 5], ['First', 0, 5, 1], ['Earlier', 1, 3, 5]],
                [self::execution('First', 0, 5), self::execution('Earlier', 5, 8), self::execution('Later', 8, 9)],
            ],
            'priority and arrival ties prefer input order not name or burst' => [
                [['Z', 0, 4, 3], ['A', 0, 1, 3], ['M', 0, 2, 3]],
                [self::execution('Z', 0, 4), self::execution('A', 4, 5), self::execution('M', 5, 7)],
            ],
            'later higher priority does not preempt and beats waiting lower priority' => [
                [['Running', 0, 7, 10], ['Waiting', 1, 1, 5], ['High', 2, 2, 1]],
                [self::execution('Running', 0, 7), self::execution('High', 7, 9), self::execution('Waiting', 9, 10)],
            ],
            'future higher priority cannot delay ready lower priority' => [
                [['High', 20, 1, 1], ['Ready', 0, 4, 10]],
                [self::execution('Ready', 0, 4), self::idle(4, 20), self::execution('High', 20, 21)],
            ],
            'idle jumps to earliest arrival not highest future priority' => [
                [['High', 10, 1, 1], ['Low', 3, 2, 10]],
                [self::idle(0, 3), self::execution('Low', 3, 5), self::idle(5, 10), self::execution('High', 10, 11)],
            ],
            'multiple idle gaps' => [
                [['A', 0, 2, 5], ['B', 5, 1, 3], ['C', 10, 2, 1]],
                [self::execution('A', 0, 2), self::idle(2, 5), self::execution('B', 5, 6), self::idle(6, 10), self::execution('C', 10, 12)],
            ],
            'single process' => [
                [['Only', 0, 7, 10]],
                [self::execution('Only', 0, 7)],
            ],
            'single delayed process' => [
                [['Only', 8, 3, 1]],
                [self::idle(0, 8), self::execution('Only', 8, 11)],
            ],
            'unordered input selects best available at each completion' => [
                [['Later', 6, 1, 1], ['Low', 1, 2, 10], ['First', 0, 4, 5], ['Medium', 2, 3, 3]],
                [self::execution('First', 0, 4), self::execution('Medium', 4, 7), self::execution('Later', 7, 8), self::execution('Low', 8, 10)],
            ],
            'higher priority arriving exactly at completion is eligible' => [
                [['First', 0, 3, 5], ['Waiting', 1, 1, 10], ['High', 3, 2, 1]],
                [self::execution('First', 0, 3), self::execution('High', 3, 5), self::execution('Waiting', 5, 6)],
            ],
            'empty input' => [[], []],
        ];
    }

    public function test_scheduler_does_not_keep_state_between_batches(): void
    {
        $scheduler = new PriorityScheduler;
        $scheduler->schedule([new SchedulingProcess('Previous', 10, 20, 1)]);

        $this->assertSame([self::execution('Next', 0, 2)], $scheduler->schedule([
            new SchedulingProcess('Next', 0, 2, 10),
        ]));
        $this->assertSame([], $scheduler->schedule([]));
    }

    private static function execution(string $id, int $start, int $end): array
    {
        return ['type' => 'process', 'process_id' => $id, 'start_time' => $start, 'end_time' => $end];
    }

    private static function idle(int $start, int $end): array
    {
        return ['type' => 'idle', 'process_id' => null, 'start_time' => $start, 'end_time' => $end];
    }
}
