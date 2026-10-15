<?php

namespace App\Services\Scheduling;

class PriorityScheduler
{
    /**
     * Non-preemptive scheduling: lower number means higher priority.
     * Only ready processes compete; ties use arrival, then original input order.
     * Processes must already be validated by ProcessInput.
     * Intervals are half-open [start_time, end_time); null process_id means idle.
     *
     * @param  list<SchedulingProcess>  $processes
     * @return list<array{type: 'process'|'idle', process_id: string|null, start_time: int, end_time: int}>
     */
    public function schedule(array $processes): array
    {
        $pending = [];
        foreach (array_values($processes) as $position => $process) {
            $pending[] = ['process' => $process, 'position' => $position];
        }

        usort($pending, fn (array $a, array $b): int => ($a['process']->priority <=> $b['process']->priority)
            ?: ($a['process']->arrivalTime <=> $b['process']->arrivalTime)
            ?: ($a['position'] <=> $b['position']));

        $time = 0;
        $execution = [];

        while ($pending !== []) {
            $selected = null;
            $nextArrival = PHP_INT_MAX;

            foreach ($pending as $index => $entry) {
                if ($entry['process']->arrivalTime <= $time) {
                    $selected = $index;
                    break;
                }

                $nextArrival = min($nextArrival, $entry['process']->arrivalTime);
            }

            if ($selected === null) {
                $execution[] = [
                    'type' => 'idle',
                    'process_id' => null,
                    'start_time' => $time,
                    'end_time' => $nextArrival,
                ];
                $time = $nextArrival;

                continue;
            }

            $process = $pending[$selected]['process'];
            $end = $time + $process->burstTime;
            $execution[] = [
                'type' => 'process',
                'process_id' => $process->id,
                'start_time' => $time,
                'end_time' => $end,
            ];
            $time = $end;
            unset($pending[$selected]);
        }

        return $execution;
    }
}
