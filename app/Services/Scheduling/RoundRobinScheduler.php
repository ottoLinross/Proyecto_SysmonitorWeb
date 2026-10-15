<?php

namespace App\Services\Scheduling;

use InvalidArgumentException;
use SplQueue;

class RoundRobinScheduler
{
    /**
     * Processes must already be validated by ProcessInput; quantum must be a positive PHP integer.
     * Arrivals up to and including the slice end enter FIFO before the current process is requeued.
     * Intervals are half-open [start_time, end_time); null process_id means idle.
     *
     * @param  list<SchedulingProcess>  $processes
     * @return list<array{type: 'process'|'idle', process_id: string|null, start_time: int, end_time: int}>
     */
    public function schedule(array $processes, mixed $quantum): array
    {
        if (! is_int($quantum) || $quantum <= 0) {
            throw new InvalidArgumentException('El quantum debe ser un entero mayor que cero.');
        }

        $arrivals = [];
        foreach (array_values($processes) as $position => $process) {
            $arrivals[] = ['process' => $process, 'remaining' => $process->burstTime, 'position' => $position];
        }

        usort($arrivals, fn (array $a, array $b): int => ($a['process']->arrivalTime <=> $b['process']->arrivalTime)
            ?: ($a['position'] <=> $b['position']));

        $queue = new SplQueue;
        $next = 0;
        $count = count($arrivals);
        $time = 0;
        $execution = [];

        while ($next < $count || ! $queue->isEmpty()) {
            if ($queue->isEmpty() && $time < $arrivals[$next]['process']->arrivalTime) {
                $end = $arrivals[$next]['process']->arrivalTime;
                $execution[] = ['type' => 'idle', 'process_id' => null, 'start_time' => $time, 'end_time' => $end];
                $time = $end;
            }

            while ($next < $count && $arrivals[$next]['process']->arrivalTime <= $time) {
                $queue->enqueue($arrivals[$next++]);
            }

            $entry = $queue->dequeue();
            $duration = min($quantum, $entry['remaining']);
            $end = $time + $duration;
            $last = array_key_last($execution);

            if ($last !== null && $execution[$last]['type'] === 'process'
                && $execution[$last]['process_id'] === $entry['process']->id
                && $execution[$last]['end_time'] === $time) {
                $execution[$last]['end_time'] = $end;
            } else {
                $execution[] = [
                    'type' => 'process',
                    'process_id' => $entry['process']->id,
                    'start_time' => $time,
                    'end_time' => $end,
                ];
            }

            $time = $end;
            $entry['remaining'] -= $duration;

            while ($next < $count && $arrivals[$next]['process']->arrivalTime <= $time) {
                $queue->enqueue($arrivals[$next++]);
            }

            if ($entry['remaining'] > 0) {
                $queue->enqueue($entry);
            }
        }

        return $execution;
    }
}
