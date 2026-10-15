<?php

namespace App\Services\Scheduling;

use App\Models\SchedulingRun;

class SchedulingRunStore
{
    /** @param list<SchedulingProcess> $processes */
    public function saveSimulation(array $processes, array $simulation): SchedulingRun
    {
        return $this->save($processes, 'single', $simulation['algorithm'], $simulation['quantum'], [$simulation]);
    }

    /** @param list<SchedulingProcess> $processes */
    public function saveComparison(array $processes, array $comparison): SchedulingRun
    {
        return $this->save($processes, 'comparison', null, $comparison['quantum'], $comparison['results']);
    }

    private function save(array $processes, string $mode, ?string $algorithm, ?int $quantum, array $results): SchedulingRun
    {
        // Store only server-calculated results, never client-supplied metrics or segments.
        $attributes = [
            'schema_version' => 1,
            'mode' => $mode,
            'algorithm' => $algorithm,
            'quantum' => $quantum,
            'input_processes' => array_map(fn (SchedulingProcess $process): array => [
                'id' => $process->id,
                'arrival_time' => $process->arrivalTime,
                'burst_time' => $process->burstTime,
                'priority' => $process->priority,
            ], $processes),
            'results' => $results,
        ];

        return (new SchedulingRun)->getConnection()->transaction(fn (): SchedulingRun => SchedulingRun::create($attributes));
    }
}
