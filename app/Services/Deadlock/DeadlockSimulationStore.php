<?php

namespace App\Services\Deadlock;

use App\Models\DeadlockSimulation;

class DeadlockSimulationStore
{
    public function __construct(private ScenarioInput $input) {}

    public function save(array $data): DeadlockSimulation
    {
        $scenario = $this->input->validate($data);
        $simulation = new DeadlockSimulation;
        $simulation->n = $scenario->n;
        $simulation->m = $scenario->m;
        $simulation->allocation = $scenario->allocation;
        $simulation->max = $scenario->max;
        $simulation->available = $scenario->available;
        $simulation->save();

        return $simulation;
    }

    public function reconstruct(DeadlockSimulation $simulation): DeadlockScenario
    {
        // Validate original JSON too: array casts would erase the distinction between objects and lists.
        return $this->input->validate([
            'n' => $simulation->getRawOriginal('n'),
            'm' => $simulation->getRawOriginal('m'),
            'allocation' => $simulation->getRawOriginal('allocation'),
            'max' => $simulation->getRawOriginal('max'),
            'available' => $simulation->getRawOriginal('available'),
        ]);
    }
}
