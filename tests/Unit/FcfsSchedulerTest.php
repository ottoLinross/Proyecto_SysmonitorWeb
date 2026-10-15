<?php

namespace Tests\Unit;

use App\Services\Scheduling\FcfsScheduler;
use App\Services\Scheduling\SchedulingProcess;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FcfsSchedulerTest extends TestCase
{
    #[DataProvider('schedules')]
    public function test_fcfs_execution_matches_expected_intervals(array $input, array $expected): void
    {
        $processes = array_map(fn (array $p): SchedulingProcess => new SchedulingProcess(...$p), $input);
        $original = $processes;
        $scheduler = new FcfsScheduler;

        $execution = $scheduler->schedule($processes);

        $this->assertSame($expected, $execution);
        $this->assertSame($original, $processes);
        $this->assertSame($execution, $scheduler->schedule($processes));
    }

    public static function schedules(): array
    {
        return [
            'ordered arrivals' => [
                [['P1', 0, 3, 1], ['P2', 1, 2, 1], ['P3', 2, 1, 1]],
                [self::execution('P1', 0, 3), self::execution('P2', 3, 5), self::execution('P3', 5, 6)],
            ],
            'unordered arrivals' => [
                [['P3', 2, 1, 1], ['P1', 0, 3, 1], ['P2', 1, 2, 1]],
                [self::execution('P1', 0, 3), self::execution('P2', 3, 5), self::execution('P3', 5, 6)],
            ],
            'simultaneous arrivals preserve FIFO regardless of name and priority' => [
                [['Z', 0, 4, 10], ['A', 0, 1, 1], ['M', 0, 2, 5]],
                [self::execution('Z', 0, 4), self::execution('A', 4, 5), self::execution('M', 5, 7)],
            ],
            'ties retain input order after arrival sorting' => [
                [['Z', 3, 2, 10], ['First', 0, 5, 1], ['A', 3, 2, 1]],
                [self::execution('First', 0, 5), self::execution('Z', 5, 7), self::execution('A', 7, 9)],
            ],
            'idle before first process' => [
                [['P1', 4, 2, 1], ['P2', 5, 3, 1]],
                [self::idle(0, 4), self::execution('P1', 4, 6), self::execution('P2', 6, 9)],
            ],
            'multiple idle gaps' => [
                [['P3', 10, 1, 1], ['P1', 0, 2, 1], ['P2', 5, 1, 1]],
                [self::execution('P1', 0, 2), self::idle(2, 5), self::execution('P2', 5, 6), self::idle(6, 10), self::execution('P3', 10, 11)],
            ],
            'single process at zero' => [
                [['Only', 0, 7, 1]],
                [self::execution('Only', 0, 7)],
            ],
            'single delayed process' => [
                [['Only', 8, 3, 1]],
                [self::idle(0, 8), self::execution('Only', 8, 11)],
            ],
            'arrival exactly at completion has no idle interval' => [
                [['P1', 0, 2, 1], ['P2', 2, 3, 1], ['P3', 5, 1, 1]],
                [self::execution('P1', 0, 2), self::execution('P2', 2, 5), self::execution('P3', 5, 6)],
            ],
            'arrival during execution never preempts current process' => [
                [['Long', 0, 10, 10], ['Short', 1, 1, 1], ['Medium', 2, 3, 2]],
                [self::execution('Long', 0, 10), self::execution('Short', 10, 11), self::execution('Medium', 11, 14)],
            ],
            'empty input has no execution or idle tail' => [[], []],
        ];
    }

    public function test_scheduler_does_not_keep_state_between_batches(): void
    {
        $scheduler = new FcfsScheduler;
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
