<?php

namespace App\Services\Deadlock;

use Illuminate\Validation\ValidationException;

class RecoveryInput
{
    public function __construct(private ScenarioInput $input) {}

    public function validate(array $data, DeadlockScenario $scenario): RecoveryChoice
    {
        $this->input->checkPayloadSize($data);
        $strategy = $data['strategy'] ?? null;
        if (! in_array($strategy, [RecoveryChoice::TERMINATE, RecoveryChoice::VICTIM, RecoveryChoice::PREEMPTION], true)) {
            throw ValidationException::withMessages(['strategy' => 'Selecciona una estrategia de recuperación válida.']);
        }

        return new RecoveryChoice($strategy, $strategy === RecoveryChoice::TERMINATE
            ? $this->input->processPosition($data['recovery_process'] ?? null, $scenario->n)
            : null);
    }
}
