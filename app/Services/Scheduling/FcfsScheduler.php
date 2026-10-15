<?php

namespace App\Services\Scheduling;

class FcfsScheduler
{
    /**
     * Schedule a batch already validated by ProcessInput, without changing it.
     * Equal arrival times preserve input order, independently of ID or priority.
     * Intervals are half-open [start_time, end_time); null process_id means idle.
     *
     * @param  list<SchedulingProcess>  $processes
     * @return list<array{type: 'process'|'idle', process_id: string|null, start_time: int, end_time: int}>
     */
    public function schedule(array $processes): array
    {
        $queue = [];
        foreach (array_values($processes) as $position => $process) {
            $queue[] = ['process' => $process, 'position' => $position];
        }

        usort($queue, fn (array $a, array $b): int => ($a['process']->arrivalTime <=> $b['process']->arrivalTime)
            ?: ($a['position'] <=> $b['position']));

        $time = 0;
        $execution = [];

        foreach ($queue as $entry) {
            $process = $entry['process'];

            if ($time < $process->arrivalTime) {
                $execution[] = [
                    'type' => 'idle',
                    'process_id' => null,
                    'start_time' => $time,
                    'end_time' => $process->arrivalTime,
                ];
                $time = $process->arrivalTime;
            }

            $end = $time + $process->burstTime;
            $execution[] = [
                'type' => 'process',
                'process_id' => $process->id,
                'start_time' => $time,
                'end_time' => $end,
            ];
            $time = $end;
        }

        return $execution;
    }
}
