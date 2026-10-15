<?php

namespace Tests\Unit;

use App\Services\Scheduling\SchedulingProcess;
use App\Services\Scheduling\SrtfScheduler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SrtfSchedulerTest extends TestCase
{
    #[DataProvider('schedules')]
    public function test_srtf_execution_and_burst_conservation(array $input, array $expected): void
    {
        $processes = array_map(fn (array $p): SchedulingProcess => new SchedulingProcess(...$p), $input);
        $original = $processes;
        $scheduler = new SrtfScheduler;

        $execution = $scheduler->schedule($processes);

        $this->assertSame($expected, $execution);
        $this->assertSame($original, $processes);
        $this->assertSame($execution, $scheduler->schedule($processes));

        $executed = array_fill_keys(array_column($input, 0), 0);
        $time = 0;
        $previous = null;
        foreach ($execution as $segment) {
            $this->assertSame($time, $segment['start_time']);
            $this->assertGreaterThan($segment['start_time'], $segment['end_time']);
            if ($segment['type'] === 'process') {
                $this->assertArrayHasKey($segment['process_id'], $executed);
                $this->assertNotSame($previous, $segment['process_id'], 'Adjacent intervals must be merged.');
                $executed[$segment['process_id']] += $segment['end_time'] - $segment['start_time'];
            }
            $time = $segment['end_time'];
            $previous = $segment['process_id'];
        }

        foreach ($processes as $process) {
            $this->assertSame($process->burstTime, $executed[$process->id]);
        }
        $this->assertSame(array_sum(array_column($input, 2)), array_sum($executed));
    }

    public static function schedules(): array
    {
        return [
            'no preemption' => [
                [['P1', 0, 2, 1], ['P2', 1, 5, 1]],
                [self::execution('P1', 0, 2), self::execution('P2', 2, 7)],
            ],
            'one preemption and resumption' => [
                [['Long', 0, 8, 1], ['Short', 2, 2, 1]],
                [self::execution('Long', 0, 2), self::execution('Short', 2, 4), self::execution('Long', 4, 10)],
            ],
            'multiple preemptions including a resumed process' => [
                [['A', 0, 10, 1], ['B', 2, 5, 1], ['C', 3, 1, 1], ['D', 5, 1, 1]],
                [self::execution('A', 0, 2), self::execution('B', 2, 3), self::execution('C', 3, 4),
                    self::execution('B', 4, 5), self::execution('D', 5, 6), self::execution('B', 6, 9), self::execution('A', 9, 17)],
            ],
            'equal remaining arrival does not preempt earlier process' => [
                [['Running', 0, 5, 10], ['New', 2, 3, 1]],
                [self::execution('Running', 0, 5), self::execution('New', 5, 8)],
            ],
            'remaining ties prefer earlier arrival even after preemption' => [
                [['A', 0, 6, 10], ['B', 1, 3, 1], ['C', 2, 5, 1]],
                [self::execution('A', 0, 1), self::execution('B', 1, 4), self::execution('A', 4, 9), self::execution('C', 9, 14)],
            ],
            'simultaneous arrivals select shortest then original order' => [
                [['Long', 0, 5, 1], ['Z', 0, 2, 10], ['A', 0, 2, 1]],
                [self::execution('Z', 0, 2), self::execution('A', 2, 4), self::execution('Long', 4, 9)],
            ],
            'idle before first arrival and between processes' => [
                [['LateShort', 10, 1, 1], ['Early', 3, 2, 1]],
                [self::idle(0, 3), self::execution('Early', 3, 5), self::idle(5, 10), self::execution('LateShort', 10, 11)],
            ],
            'single process' => [
                [['Only', 0, 7, 1]],
                [self::execution('Only', 0, 7)],
            ],
            'single delayed process' => [
                [['Only', 8, 3, 1]],
                [self::idle(0, 8), self::execution('Only', 8, 11)],
            ],
            'new burst smaller than original but greater than remaining cannot preempt' => [
                [['Running', 0, 10, 10], ['New', 8, 3, 1]],
                [self::execution('Running', 0, 10), self::execution('New', 10, 13)],
            ],
            'consecutive arrival events do not fragment unchanged execution' => [
                [['Running', 0, 5, 1], ['B', 1, 10, 1], ['C', 2, 10, 1], ['D', 3, 10, 1]],
                [self::execution('Running', 0, 5), self::execution('B', 5, 15), self::execution('C', 15, 25), self::execution('D', 25, 35)],
            ],
            'unordered input and simultaneous new arrivals' => [
                [['C', 2, 3, 1], ['A', 0, 8, 1], ['B', 2, 1, 1]],
                [self::execution('A', 0, 2), self::execution('B', 2, 3), self::execution('C', 3, 6), self::execution('A', 6, 12)],
            ],
            'arrival exactly at completion has no zero duration interval' => [
                [['A', 0, 2, 1], ['B', 2, 1, 1], ['C', 2, 3, 1]],
                [self::execution('A', 0, 2), self::execution('B', 2, 3), self::execution('C', 3, 6)],
            ],
            'empty input' => [[], []],
        ];
    }

    public function test_scheduler_has_no_state_between_batches(): void
    {
        $scheduler = new SrtfScheduler;
        $scheduler->schedule([new SchedulingProcess('Previous', 10, 20, 1)]);

        $this->assertSame([self::execution('Next', 0, 2)], $scheduler->schedule([
            new SchedulingProcess('Next', 0, 2, 1),
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
