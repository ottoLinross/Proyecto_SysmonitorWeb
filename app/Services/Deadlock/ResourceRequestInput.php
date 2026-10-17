<?php

namespace App\Services\Deadlock;

class ResourceRequestInput
{
    public function __construct(private ScenarioInput $input) {}

    public function validate(array $data, DeadlockScenario $scenario): ResourceRequest
    {
        $this->input->checkPayloadSize($data);

        return new ResourceRequest(
            $this->input->processPosition($data['process'] ?? null, $scenario->n),
            $this->input->resourceVector($data['request'] ?? null, 'request', $scenario->m),
        );
    }
}
