<?php

namespace App\Services\Deadlock;

class ResourceRequestEvaluator
{
    public function __construct(private NeedCalculator $calculator, private BankerSafetyAnalyzer $analyzer) {}

    /** Both the scenario and the resource request must already be validated. */
    public function evaluate(DeadlockScenario $scenario, ResourceRequest $request): ResourceRequestResult
    {
        $need = $this->calculator->calculate($scenario);
        $i = $request->process;
        foreach ($request->vector as $j => $value) {
            if ($value > $need[$i][$j]) {
                return new ResourceRequestResult($request, ResourceRequestResult::INVALID, 'Solicitud inválida: Request supera Need en el recurso '.($j + 1).'.');
            }
        }
        foreach ($request->vector as $j => $value) {
            if ($value > $scenario->available[$j]) {
                return new ResourceRequestResult($request, ResourceRequestResult::WAIT, 'Recursos insuficientes: la solicitud debe esperar; Request supera Available en el recurso '.($j + 1).'.');
            }
        }

        $available = $scenario->available;
        $allocation = $scenario->allocation;
        foreach ($request->vector as $j => $value) {
            $available[$j] -= $value;
            $allocation[$i][$j] += $value;
        }
        $simulated = new DeadlockScenario($scenario->n, $scenario->m, $allocation, $scenario->max, $available);
        // NeedCalculator inside the analyzer derives Need' from Max - Allocation'.
        $safety = $this->analyzer->analyze($simulated);
        if (! $safety->isSafe) {
            return new ResourceRequestResult($request, ResourceRequestResult::UNSAFE, 'Concesión denegada: el estado temporal es inseguro. Se conserva el escenario original; esto no confirma un interbloqueo.', safety: $safety);
        }

        return new ResourceRequestResult($request, ResourceRequestResult::GRANTED, 'Concesión segura únicamente en la simulación. El escenario original no se modifica.', $simulated, $safety);
    }
}
