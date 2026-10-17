<?php

namespace App\Services\Deadlock;

class ResourceGraphBuilder
{
    public function __construct(private NeedCalculator $calculator) {}

    /** Build from a ScenarioInput-validated scenario and an optional ResourceRequestInput-validated request. */
    public function build(DeadlockScenario $scenario, ?ResourceRequest $request = null): ResourceGraph
    {
        $nodes = [];
        $edges = [];
        $need = $this->calculator->calculate($scenario);

        for ($i = 0; $i < $scenario->n; $i++) {
            $nodes[] = ['id' => 'P'.($i + 1), 'type' => 'process'];
        }
        for ($j = 0; $j < $scenario->m; $j++) {
            $total = $scenario->available[$j];
            foreach ($scenario->allocation as $row) {
                $total += $row[$j];
            }
            $nodes[] = ['id' => 'R'.($j + 1), 'type' => 'resource', 'available' => $scenario->available[$j], 'total' => $total];
        }

        // Numeric process/resource order; within each pair: Allocation, Need, Request.
        for ($i = 0; $i < $scenario->n; $i++) {
            for ($j = 0; $j < $scenario->m; $j++) {
                $process = 'P'.($i + 1);
                $resource = 'R'.($j + 1);
                if ($scenario->allocation[$i][$j] > 0) {
                    $edges[] = ['source' => $resource, 'target' => $process, 'type' => 'allocation', 'quantity' => $scenario->allocation[$i][$j]];
                }
                if ($need[$i][$j] > 0) {
                    $edges[] = ['source' => $process, 'target' => $resource, 'type' => 'need', 'quantity' => $need[$i][$j]];
                }
                if ($request !== null && $request->process === $i && $request->vector[$j] > 0) {
                    $edges[] = ['source' => $process, 'target' => $resource, 'type' => 'request', 'quantity' => $request->vector[$j]];
                }
            }
        }

        return new ResourceGraph($nodes, $edges);
    }
}
