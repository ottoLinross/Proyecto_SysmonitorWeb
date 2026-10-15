<?php

namespace App\Services\Scheduling;

use InvalidArgumentException;

class SchedulingSimulation
{
    public const ALGORITHMS = [
        'fcfs' => 'FCFS',
        'sjf' => 'SJF no expropiativo',
        'srtf' => 'SRTF',
        'round_robin' => 'Round Robin',
        'priority' => 'Prioridades no expropiativo',
    ];

    public function __construct(private SchedulingMetrics $metrics) {}

    /** @param list<SchedulingProcess> $processes */
    public function run(array $processes, string $algorithm, mixed $quantum = null): array
    {
        $segments = match ($algorithm) {
            'fcfs' => (new FcfsScheduler)->schedule($processes),
            'sjf' => (new SjfScheduler)->schedule($processes),
            'srtf' => (new SrtfScheduler)->schedule($processes),
            'round_robin' => (new RoundRobinScheduler)->schedule($processes, $quantum),
            'priority' => (new PriorityScheduler)->schedule($processes),
            default => throw new InvalidArgumentException('Algoritmo no válido.'),
        };

        return [
            'algorithm' => $algorithm,
            'label' => self::ALGORITHMS[$algorithm],
            'quantum' => $algorithm === 'round_robin' ? $quantum : null,
            'segments' => $segments,
            'metrics' => $this->metrics->calculate($processes, $segments),
        ];
    }
}
