<?php

namespace Tests\Unit;

use App\Services\Scheduling\SchedulingProcess;
use App\Services\Scheduling\SjfScheduler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SjfSchedulerTest extends TestCase
{
    #[DataProvider('schedules')]
    public function test_sjf_execution_matches_expected_intervals(array $input, array $expected): void
    {
        $processes = array_map(fn (array $p): SchedulingProcess => new SchedulingProcess(...$p), $input);
        $original = $processes;
        $scheduler = new SjfScheduler;

        $execution = $scheduler->schedule($processes);

        $this->assertSame($expected, $execution);
        $this->assertSame($original, $processes);
        $this->assertSame($execution, $scheduler->schedule($processes));
    }

    public static function schedules(): array
    {
        return [
            'normal order selects shortest burst' => [
                [['Long', 0, 6, 1], ['Short', 0, 1, 10], ['Medium', 0, 3, 5]],
                [self::execution('Short', 0, 1), self::execution('Medium', 1, 4), self::execution('Long', 4, 10)],
            ],
            'future short process cannot delay a ready process' => [
                [['Future', 20, 1, 1], ['Ready', 0, 5, 1]],
                [self::execution('Ready', 0, 5), self::idle(5, 20), self::execution('Future', 20, 21)],
            ],
            'short arrival never preempts running process' => [
                [['Long', 0, 8, 1], ['Waiting', 1, 4, 1], ['Short', 2, 1, 1]],
                [self::execution('Long', 0, 8), self::execution('Short', 8, 9), self::execution('Waiting', 9, 13)],
            ],
            'equal bursts prefer earlier arrival over original order' => [
                [['Later', 2, 3, 1], ['First', 0, 6, 1], ['Earlier', 1, 3, 10]],
                [self::execution('First', 0, 6), self::execution('Earlier', 6, 9), self::execution('Later', 9, 12)],
            ],
            'equal bursts and arrivals preserve original order not name or priority' => [
                [['Z', 0, 2, 10], ['A', 0, 2, 1], ['M', 0, 2, 5]],
                [self::execution('Z', 0, 2), self::execution('A', 2, 4), self::execution('M', 4, 6)],
            ],
            'idle jumps to earliest arrival not shortest future burst' => [
                [['Short', 10, 1, 1], ['Earlier', 3, 2, 1]],
                [self::idle(0, 3), self::execution('Earlier', 3, 5), self::idle(5, 10), self::execution('Short', 10, 11)],
            ],
            'multiple idle gaps' => [
                [['P1', 0, 2, 1], ['P2', 5, 1, 1], ['P3', 10, 3, 1]],
                [self::execution('P1', 0, 2), self::idle(2, 5), self::execution('P2', 5, 6), self::idle(6, 10), self::execution('P3', 10, 13)],
            ],
            'single process at zero' => [
                [['Only', 0, 7, 1]],
                [self::execution('Only', 0, 7)],
            ],
            'single delayed process' => [
                [['Only', 8, 3, 1]],
                [self::idle(0, 8), self::execution('Only', 8, 11)],
            ],
            'unordered input reselects shortest available at each completion' => [
                [['P4', 6, 1, 1], ['P2', 1, 5, 1], ['P1', 0, 4, 1], ['P3', 2, 2, 1]],
                [self::execution('P1', 0, 4), self::execution('P3', 4, 6), self::execution('P4', 6, 7), self::execution('P2', 7, 12)],
            ],
            'arrival exactly at completion is eligible without idle' => [
                [['P1', 0, 3, 1], ['Waiting', 1, 5, 1], ['New', 3, 1, 1]],
                [self::execution('P1', 0, 3), self::execution('New', 3, 4), self::execution('Waiting', 4, 9)],
            ],
            'empty input has no execution or idle tail' => [[], []],
        ];
    }

    public function test_scheduler_does_not_keep_state_between_batches(): void
    {
        $scheduler = new SjfScheduler;
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
