<?php

namespace Tests\Unit;

use App\Services\Scheduling\SchedulingComparison;
use App\Services\Scheduling\SchedulingMetrics;
use App\Services\Scheduling\SchedulingProcess;
use App\Services\Scheduling\SchedulingSimulation;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class SchedulingComparisonTest extends TestCase
{
    public function test_known_metrics_for_all_five_algorithms_in_fixed_order(): void
    {
        $result = $this->comparison()->compare($this->processes(), 2);

        $this->assertSame(2, $result['quantum']);
        $this->assertSame(['fcfs', 'sjf', 'srtf', 'round_robin', 'priority'], array_column($result['results'], 'algorithm'));
        $this->assertSame(['FCFS', 'SJF no expropiativo', 'SRTF', 'Round Robin', 'Prioridades no expropiativo'], array_column($result['results'], 'label'));
        $expected = [[9, 17], [8, 16], [4, 12], [6, 14], [9, 17]];
        foreach ($result['results'] as $index => $row) {
            $metrics = $row['metrics'];
            $this->assertSame(['A', 'B', 'C'], array_column($metrics['processes'], 'id'));
            $this->assertSame(3, $metrics['process_count']);
            $this->assertSame($expected[$index][0], $metrics['total_waiting_time']);
            $this->assertSame($expected[$index][1], $metrics['total_turnaround_time']);
            $this->assertSame($expected[$index][0] / 3, $metrics['average_waiting_time']);
            $this->assertSame($expected[$index][1] / 3, $metrics['average_turnaround_time']);
        }
    }

    public function test_every_algorithm_receives_independent_copies_of_exactly_the_same_input(): void
    {
        // Deliberately unordered, so reordering the caller's array would be observable.
        $processes = array_reverse($this->processes());
        $original = $processes;
        $calls = [];
        $real = new SchedulingSimulation(new SchedulingMetrics);
        $simulation = $this->createMock(SchedulingSimulation::class);
        $simulation->expects($this->exactly(5))->method('run')->willReturnCallback(
            function (array $copy, string $algorithm, mixed $quantum) use (&$calls, $processes, $real): array {
                $this->assertEquals($processes, $copy);
                foreach ($copy as $index => $process) {
                    $this->assertNotSame($processes[$index], $process);
                    foreach ($calls as $previous) {
                        $this->assertNotSame($previous[$index], $process);
                    }
                }
                $this->assertSame(2, $quantum);
                $calls[] = $copy;

                return $real->run($copy, $algorithm, $quantum);
            }
        );

        (new SchedulingComparison($simulation))->compare($processes, 2);

        $this->assertSame($original, $processes);
    }

    public function test_repeated_comparisons_and_reverse_execution_order_do_not_contaminate_results(): void
    {
        $processes = $this->processes();
        $original = $processes;
        $comparison = $this->comparison();
        $first = $comparison->compare($processes, 2);
        $simulation = new SchedulingSimulation(new SchedulingMetrics);
        foreach (array_reverse($first['results']) as $row) {
            $single = $simulation->run($processes, $row['algorithm'], 2);
            $this->assertSame($row['metrics'], $single['metrics']);
        }
        $comparison->compare([new SchedulingProcess('Other', 10, 3, 1)], 1);
        $this->assertSame($first, $comparison->compare($processes, 2));
        $this->assertSame($original, $processes);
    }

    public function test_configured_quantum_changes_only_round_robin(): void
    {
        $comparison = $this->comparison();
        $small = $comparison->compare($this->processes(), 2)['results'];
        $large = $comparison->compare($this->processes(), 5)['results'];

        foreach ([0, 1, 2, 4] as $index) {
            $this->assertSame($small[$index], $large[$index]);
        }
        $this->assertSame(2, $small[3]['quantum']);
        $this->assertSame(5, $large[3]['quantum']);
        $this->assertSame(2, $small[3]['metrics']['average_waiting_time']);
        $this->assertSame(3, $large[3]['metrics']['average_waiting_time']);
        $this->assertSame(14 / 3, $small[3]['metrics']['average_turnaround_time']);
        $this->assertSame(17 / 3, $large[3]['metrics']['average_turnaround_time']);
    }

    public function test_invalid_quantum_cannot_produce_a_comparison(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->comparison()->compare($this->processes(), 0);
    }

    private function comparison(): SchedulingComparison
    {
        return new SchedulingComparison(new SchedulingSimulation(new SchedulingMetrics));
    }

    private function processes(): array
    {
        return [new SchedulingProcess('A', 0, 5, 5), new SchedulingProcess('B', 1, 2, 1), new SchedulingProcess('C', 2, 1, 3)];
    }
}
