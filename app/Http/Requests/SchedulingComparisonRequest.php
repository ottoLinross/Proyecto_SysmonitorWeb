<?php

namespace App\Http\Requests;

class SchedulingComparisonRequest extends SchedulingSimulationRequest
{
    public function rules(): array
    {
        return ['quantum' => $this->quantumRules()];
    }
}
