<?php

namespace App\Services\Scheduling;

class SrtfScheduler
{
    /**
     * Schedule processes already validated by ProcessInput, reconsidering each arrival.
     * Ties use arrival time, then original input order; priority and ID are ignored.
     * Intervals are half-open [start_time, end_time); null process_id means idle.
     *
     * @param  list<SchedulingProcess>  $processes
     * @return list<array{type: 'process'|'idle', process_id: string|null, start_time: int, end_time: int}>
     */
    public function schedule(array $processes): array
    {
        $pending = [];
        foreach (array_values($processes) as $position => $process) {
            $pending[$position] = ['process' => $process, 'remaining' => $process->burstTime];
        }

        $time = 0;
        $execution = [];

        while ($pending !== []) {
            $selected = null;
            $best = null;
            $nextArrival = PHP_INT_MAX;

            foreach ($pending as $position => $entry) {
                if ($entry['process']->arrivalTime > $time) {
                    $nextArrival = min($nextArrival, $entry['process']->arrivalTime);

                    continue;
                }

                $rank = [$entry['remaining'], $entry['process']->arrivalTime, $position];
                if ($best === null || ($rank <=> $best) < 0) {
                    $selected = $position;
                    $best = $rank;
                }
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
            $end = min($time + $pending[$selected]['remaining'], $nextArrival);
            $last = array_key_last($execution);

            if ($last !== null && $execution[$last]['type'] === 'process'
                && $execution[$last]['process_id'] === $process->id
                && $execution[$last]['end_time'] === $time) {
                $execution[$last]['end_time'] = $end;
            } else {
                $execution[] = [
                    'type' => 'process',
                    'process_id' => $process->id,
                    'start_time' => $time,
                    'end_time' => $end,
                ];
            }

            $pending[$selected]['remaining'] -= $end - $time;
            $time = $end;

            if ($pending[$selected]['remaining'] === 0) {
                unset($pending[$selected]);
            }
        }

        return $execution;
    }
}
