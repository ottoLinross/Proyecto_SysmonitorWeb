<?php

namespace App\Services\System;

class ProcessRankingService
{
    /**
     * Recibe los procesos ya validados por ProcessService, sin leer el sistema.
     *
     * @return array{cpu: list<array>, memory: list<array>}
     */
    public function getRankings(array $processes): array
    {
        return [
            'cpu' => $this->topBy($processes, 'cpu_percent'),
            'memory' => $this->topBy($processes, 'memory_percent'),
        ];
    }

    /** @return list<array> */
    private function topBy(array $processes, string $metric): array
    {
        $valid = [];
        foreach ($processes as $process) {
            if (! is_array($process)) {
                continue;
            }

            $pid = $process['pid'] ?? null;
            $value = $process[$metric] ?? null;
            $command = $process['command'] ?? null;

            if (! is_int($pid) || $pid <= 0 || ! is_string($command) || trim($command) === ''
                || (! is_int($value) && ! is_float($value)) || ! is_finite((float) $value) || $value < 0
                || ($metric === 'memory_percent' && $value > 100) || isset($valid[$pid])) {
                continue;
            }

            $valid[$pid] = $process;
        }

        $processes = array_values($valid);
        usort($processes, fn (array $left, array $right): int => ($right[$metric] <=> $left[$metric]) ?: ($left['pid'] <=> $right['pid']));

        return array_slice($processes, 0, 5);
    }
}
