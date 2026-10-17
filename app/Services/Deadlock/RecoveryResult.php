<?php

namespace App\Services\Deadlock;

final readonly class RecoveryResult
{
    /**
     * @param  list<int>  $released  Resource units in original column order.
     * @param  list<int>  $activeProcesses  Maps temporary row indices to original process positions.
     */
    public function __construct(
        public string $strategy,
        public ?int $victim,
        public array $released,
        public ?DeadlockScenario $simulated,
        public ?SafetyResult $safety,
        public array $activeProcesses,
    ) {}
}
