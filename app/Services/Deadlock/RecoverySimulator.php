<?php

namespace App\Services\Deadlock;

class RecoverySimulator
{
    public function __construct(private BankerSafetyAnalyzer $analyzer) {}

    /** Educational simulation from a validated original scenario and RecoveryInput choice. */
    public function simulate(DeadlockScenario $scenario, RecoveryChoice $choice): RecoveryResult
    {
        if ($choice->strategy === RecoveryChoice::PREEMPTION) {
            return new RecoveryResult($choice->strategy, null, [], null, null, []);
        }

        $victim = $choice->process;
        if ($choice->strategy === RecoveryChoice::VICTIM) {
            // Greatest sum of allocated units; ties retain the lowest original position.
            $victim = 0;
            foreach ($scenario->allocation as $i => $row) {
                if (array_sum($row) > array_sum($scenario->allocation[$victim])) {
                    $victim = $i;
                }
            }
        }

        $released = $scenario->allocation[$victim];
        $available = $scenario->available;
        foreach ($released as $j => $quantity) {
            $available[$j] += $quantity;
        }
        $allocation = [];
        $max = [];
        $active = [];
        for ($i = 0; $i < $scenario->n; $i++) {
            if ($i !== $victim) {
                $active[] = $i;
                $allocation[] = $scenario->allocation[$i];
                $max[] = $scenario->max[$i];
            }
        }
        // Internal empty scenario is valid after retiring the last process.
        $simulated = new DeadlockScenario(count($active), $scenario->m, $allocation, $max, $available);

        return new RecoveryResult($choice->strategy, $victim, $released, $simulated, $this->analyzer->analyze($simulated), $active);
    }
}
