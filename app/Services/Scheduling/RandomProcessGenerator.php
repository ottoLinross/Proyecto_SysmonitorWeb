<?php

namespace App\Services\Scheduling;

use Random\Randomizer;

class RandomProcessGenerator
{
    public function __construct(private ProcessInput $input) {}

    /** @return list<SchedulingProcess> */
    public function generate(int $count, ?Randomizer $randomizer = null): array
    {
        $count = $this->input->validateCount(['count' => $count]);
        $randomizer ??= new Randomizer;
        $processes = [];

        for ($i = 1; $i <= $count; $i++) {
            $processes[] = [
                'id' => 'P'.$i,
                'arrival_time' => $randomizer->getInt(0, ProcessInput::MAX_ARRIVAL),
                'burst_time' => $randomizer->getInt(1, ProcessInput::MAX_BURST),
                'priority' => $randomizer->getInt(ProcessInput::MIN_PRIORITY, ProcessInput::MAX_PRIORITY),
            ];
        }

        return $this->input->validate(['processes' => $processes]);
    }
}
