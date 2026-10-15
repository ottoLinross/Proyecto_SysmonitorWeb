<?php

namespace Tests\Unit;

use App\Services\Scheduling\RoundRobinScheduler;
use App\Services\Scheduling\SchedulingProcess;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RoundRobinSchedulerTest extends TestCase
{
    #[DataProvider('schedules')]
    public function test_fifo_execution_and_burst_conservation(array $input, int $quantum, array $expected): void
    {
        $processes = array_map(fn (array $p): SchedulingProcess => new SchedulingProcess(...$p), $input);
        $original = $processes;
        $scheduler = new RoundRobinScheduler;

        $execution = $scheduler->schedule($processes, $quantum);

        $this->assertSame($expected, $execution);
        $this->assertSame($original, $processes);
        $this->assertSame($execution, $scheduler->schedule($processes, $quantum));

        $executed = array_fill_keys(array_column($input, 0), 0);
        $time = 0;
        $previous = null;
        foreach ($execution as $segment) {
            $this->assertSame($time, $segment['start_time']);
            $this->assertGreaterThan($segment['start_time'], $segment['end_time']);
            if ($segment['type'] === 'process') {
                $this->assertArrayHasKey($segment['process_id'], $executed);
                $this->assertNotSame($previous, $segment['process_id']);
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
            'normal quantum and repeated requeues' => [
                [['A', 0, 5, 1], ['B', 0, 3, 1], ['C', 0, 1, 1]], 2,
                [self::execution('A', 0, 2), self::execution('B', 2, 4), self::execution('C', 4, 5),
                    self::execution('A', 5, 7), self::execution('B', 7, 8), self::execution('A', 8, 9)],
            ],
            'quantum one' => [
                [['A', 0, 3, 1], ['B', 0, 2, 1]], 1,
                [self::execution('A', 0, 1), self::execution('B', 1, 2), self::execution('A', 2, 3),
                    self::execution('B', 3, 4), self::execution('A', 4, 5)],
            ],
            'large quantum exceeds every burst' => [
                [['A', 0, 3, 1], ['B', 1, 2, 1], ['C', 2, 1, 1]], 100,
                [self::execution('A', 0, 3), self::execution('B', 3, 5), self::execution('C', 5, 6)],
            ],
            'arrivals during quantum join behind waiting processes before requeue' => [
                [['A', 0, 5, 1], ['Waiting', 0, 1, 1], ['New', 1, 1, 1]], 3,
                [self::execution('A', 0, 3), self::execution('Waiting', 3, 4), self::execution('New', 4, 5), self::execution('A', 5, 7)],
            ],
            'arrival exactly at slice boundary precedes requeued process' => [
                [['A', 0, 4, 1], ['B', 2, 1, 1]], 2,
                [self::execution('A', 0, 2), self::execution('B', 2, 3), self::execution('A', 3, 5)],
            ],
            'unordered arrivals within slice enter chronologically' => [
                [['Late', 2, 1, 1], ['A', 0, 5, 1], ['Early', 1, 1, 1]], 3,
                [self::execution('A', 0, 3), self::execution('Early', 3, 4), self::execution('Late', 4, 5), self::execution('A', 5, 7)],
            ],
            'simultaneous boundary arrivals preserve original order not priority or name' => [
                [['A', 0, 4, 1], ['Z', 2, 1, 10], ['B', 2, 1, 1]], 2,
                [self::execution('A', 0, 2), self::execution('Z', 2, 3), self::execution('B', 3, 4), self::execution('A', 4, 6)],
            ],
            'process finishes early without reserving unused quantum' => [
                [['A', 0, 1, 1], ['B', 0, 2, 1]], 4,
                [self::execution('A', 0, 1), self::execution('B', 1, 3)],
            ],
            'arrival at completion after shorter than quantum burst' => [
                [['A', 0, 1, 1], ['B', 1, 2, 1]], 5,
                [self::execution('A', 0, 1), self::execution('B', 1, 3)],
            ],
            'idle initially and between processes' => [
                [['Late', 10, 1, 1], ['Early', 3, 2, 1]], 1,
                [self::idle(0, 3), self::execution('Early', 3, 5), self::idle(5, 10), self::execution('Late', 10, 11)],
            ],
            'single process merges uninterrupted quanta' => [
                [['Only', 0, 7, 1]], 2,
                [self::execution('Only', 0, 7)],
            ],
            'new arrival cannot interrupt a running quantum' => [
                [['A', 0, 10, 1], ['B', 3, 1, 1]], 2,
                [self::execution('A', 0, 4), self::execution('B', 4, 5), self::execution('A', 5, 11)],
            ],
            'maximum integer quantum does not overflow slice addition' => [
                [['Only', 1, 2, 1]], PHP_INT_MAX,
                [self::idle(0, 1), self::execution('Only', 1, 3)],
            ],
            'empty input' => [[], 2, []],
        ];
    }

    #[DataProvider('invalidQuanta')]
    public function test_invalid_quantum_is_rejected(mixed $quantum): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('El quantum debe ser un entero mayor que cero.');
        (new RoundRobinScheduler)->schedule([new SchedulingProcess('P1', 0, 2, 1)], $quantum);
    }

    public static function invalidQuanta(): array
    {
        return [[0], [-1], [1.5], [1.0], ['2'], ['abc'], [true], [null], [[]]];
    }

    public function test_invalid_quantum_is_rejected_for_empty_batch_too(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new RoundRobinScheduler)->schedule([], 0);
    }

    public function test_scheduler_does_not_keep_state_or_quantum_between_batches(): void
    {
        $scheduler = new RoundRobinScheduler;
        $scheduler->schedule([new SchedulingProcess('Previous', 10, 20, 1)], 3);

        $this->assertSame([self::execution('A', 0, 1), self::execution('B', 1, 2), self::execution('A', 2, 3)], $scheduler->schedule([
            new SchedulingProcess('A', 0, 2, 1), new SchedulingProcess('B', 0, 1, 1),
        ], 1));
        $this->assertSame([], $scheduler->schedule([], 1));
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
