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
        usort($processes, fn (array $left, array $right): int => ($right[$metric] <=> $left[$metric]) ?: ($left['pid'] <=> $right['pid']));

        return array_slice($processes, 0, 5);
    }
}
