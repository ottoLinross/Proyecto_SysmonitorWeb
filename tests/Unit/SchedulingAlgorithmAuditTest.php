<?php

namespace Tests\Unit;

use App\Services\Scheduling\ProcessInput;
use App\Services\Scheduling\SchedulingMetrics;
use App\Services\Scheduling\SchedulingProcess;
use App\Services\Scheduling\SchedulingSimulation;
use PHPUnit\Framework\TestCase;

class SchedulingAlgorithmAuditTest extends TestCase
{
    public function test_maximum_allowed_batch_conserves_burst_with_quantum_one(): void
    {
        $processes = [];
        for ($i = 1; $i <= ProcessInput::MAX_PROCESSES; $i++) {
            $processes[] = new SchedulingProcess('P'.$i, ProcessInput::MAX_ARRIVAL, ProcessInput::MAX_BURST, 1);
        }
        $original = $processes;
        $simulation = new SchedulingSimulation(new SchedulingMetrics);

        foreach (array_keys(SchedulingSimulation::ALGORITHMS) as $algorithm) {
            $result = $simulation->run($processes, $algorithm, 1);
            $segments = $result['segments'];
            $this->assertSame(['type' => 'idle', 'process_id' => null, 'start_time' => 0, 'end_time' => 10000], $segments[0]);
            $this->assertSame(60000, $segments[array_key_last($segments)]['end_time']);
            $this->assertCount($algorithm === 'round_robin' ? 50001 : 51, $segments);
            $this->assertSame(50000, array_sum(array_map(fn (array $segment): int => $segment['type'] === 'process'
                ? $segment['end_time'] - $segment['start_time'] : 0, $segments)));
            $this->assertCount(50, $result['metrics']['processes']);
            foreach ($result['metrics']['processes'] as $metric) {
                $this->assertGreaterThanOrEqual(0, $metric['waiting_time']);
                $this->assertGreaterThanOrEqual(1000, $metric['turnaround_time']);
            }
            // Shared metrics reject any missing/duplicated burst before returning a result.
            $this->assertSame($original, $processes);
        }
    }
}
