<?php

namespace App\Services\Deadlock;

class NeedCalculator
{
    /**
     * Calculate remaining requirements for a scenario validated by ScenarioInput.
     *
     * @return list<list<int>>
     */
    public function calculate(DeadlockScenario $scenario): array
    {
        $need = [];

        foreach ($scenario->max as $i => $row) {
            $need[$i] = [];
            foreach ($row as $j => $value) {
                $need[$i][$j] = $value - $scenario->allocation[$i][$j];
            }
        }

        return $need;
    }
}
