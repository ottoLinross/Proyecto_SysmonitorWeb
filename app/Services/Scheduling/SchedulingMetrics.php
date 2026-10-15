<?php

namespace App\Services\Scheduling;

use InvalidArgumentException;

class SchedulingMetrics
{
    /**
     * Calculate metrics from validated processes and a complete execution timeline.
     * Keep integer totals and count as the exact fractions behind the averages.
     * Reject inconsistent timelines instead of hiding errors by clamping negatives.
     *
     * @param  list<SchedulingProcess>  $processes
     * @param  list<array{type: string, process_id: string|null, start_time: int, end_time: int}>  $segments
     */
    public function calculate(array $processes, array $segments): array
    {
        $byId = [];
        $executed = [];
        $completion = [];
        foreach ($processes as $process) {
            if (isset($byId[$process->id])) {
                throw new InvalidArgumentException('Los identificadores deben ser únicos.');
            }
            $byId[$process->id] = $process;
            $executed[$process->id] = 0;
        }

        $time = 0;
        foreach ($segments as $segment) {
            if (! is_int($segment['start_time'] ?? null) || ! is_int($segment['end_time'] ?? null)
                || $segment['start_time'] !== $time || $segment['end_time'] <= $time) {
                throw new InvalidArgumentException('La ejecución debe ser continua desde cero y tener duraciones positivas.');
            }
            if (($segment['type'] ?? null) === 'idle' && array_key_exists('process_id', $segment) && $segment['process_id'] === null) {
                $time = $segment['end_time'];

                continue;
            }
            $id = $segment['process_id'] ?? null;
            if (($segment['type'] ?? null) !== 'process' || ! is_string($id) || ! isset($byId[$id])
                || $segment['start_time'] < $byId[$id]->arrivalTime) {
                throw new InvalidArgumentException('El segmento debe pertenecer a un proceso que ya llegó.');
            }
            $executed[$id] += $segment['end_time'] - $segment['start_time'];
            $completion[$id] = $segment['end_time'];
            $time = $segment['end_time'];
        }

        $rows = [];
        $totalWaiting = 0;
        $totalTurnaround = 0;
        foreach ($processes as $process) {
            if ($executed[$process->id] !== $process->burstTime || ! isset($completion[$process->id])) {
                throw new InvalidArgumentException('El burst ejecutado debe coincidir exactamente con el burst de entrada.');
            }
            $completed = $completion[$process->id];
            $turnaround = $completed - $process->arrivalTime;
            $waiting = $turnaround - $process->burstTime;
            if ($turnaround < 0 || $waiting < 0) {
                throw new InvalidArgumentException('Las métricas no pueden contener tiempos negativos.');
            }
            $rows[] = [
                'id' => $process->id,
                'completion_time' => $completed,
                'turnaround_time' => $turnaround,
                'waiting_time' => $waiting,
            ];
            $totalWaiting += $waiting;
            $totalTurnaround += $turnaround;
        }

        $count = count($rows);

        return [
            'processes' => $rows,
            'total_waiting_time' => $totalWaiting,
            'total_turnaround_time' => $totalTurnaround,
            'process_count' => $count,
            'average_waiting_time' => $count === 0 ? 0 : $totalWaiting / $count,
            'average_turnaround_time' => $count === 0 ? 0 : $totalTurnaround / $count,
        ];
    }
}
