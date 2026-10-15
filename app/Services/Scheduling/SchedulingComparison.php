<?php

namespace App\Services\Scheduling;

class SchedulingComparison
{
    public function __construct(private SchedulingSimulation $simulation) {}

    /**
     * Use the same values and input order, with independent immutable DTO copies.
     * Presentation order follows SchedulingSimulation::ALGORITHMS, never metric ranking.
     *
     * @param  list<SchedulingProcess>  $processes
     */
    public function compare(array $processes, mixed $quantum): array
    {
        $results = [];
        foreach (array_keys(SchedulingSimulation::ALGORITHMS) as $algorithm) {
            $copy = array_map(fn (SchedulingProcess $process): SchedulingProcess => clone $process, $processes);
            $result = $this->simulation->run($copy, $algorithm, $quantum);
            $results[] = [
                'algorithm' => $result['algorithm'],
                'label' => $result['label'],
                'quantum' => $result['quantum'],
                'metrics' => $result['metrics'],
            ];
        }

        return ['quantum' => $quantum, 'results' => $results];
    }
}
