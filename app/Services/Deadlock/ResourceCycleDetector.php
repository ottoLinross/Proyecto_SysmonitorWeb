<?php

namespace App\Services\Deadlock;

class ResourceCycleDetector
{
    /** Analyze Allocation and explicit Request edges in the supplied educational snapshot, never Need. */
    public function detect(ResourceGraph $graph): ResourceCycleResult
    {
        $adjacency = [];
        foreach ($graph->nodes as $node) {
            $adjacency[$node['id']] = [];
        }

        $hasRequest = false;
        foreach ($graph->edges as $edge) {
            if (! in_array($edge['type'], ['allocation', 'request'], true) || $edge['quantity'] <= 0) {
                continue;
            }
            $adjacency[$edge['source']][] = $edge['target'];
            $hasRequest = $hasRequest || $edge['type'] === 'request';
        }
        if (! $hasRequest) {
            return new ResourceCycleResult(ResourceCycleResult::NO_REQUEST);
        }

        $states = [];
        $stack = [];
        $positions = [];
        $visit = function (string $node) use (&$visit, &$states, &$stack, &$positions, $adjacency): ?array {
            $states[$node] = 1;
            $positions[$node] = count($stack);
            $stack[] = $node;
            foreach ($adjacency[$node] as $target) {
                if (($states[$target] ?? 0) === 1) {
                    return [...array_slice($stack, $positions[$target]), $target];
                }
                if (($states[$target] ?? 0) === 0) {
                    $cycle = $visit($target);
                    if ($cycle !== null) {
                        return $cycle;
                    }
                }
            }
            array_pop($stack);
            unset($positions[$node]);
            $states[$node] = 2;

            return null;
        };

        foreach (array_keys($adjacency) as $node) {
            if (($states[$node] ?? 0) === 0) {
                $cycle = $visit($node);
                if ($cycle !== null) {
                    return new ResourceCycleResult(ResourceCycleResult::CYCLE, $cycle);
                }
            }
        }

        return new ResourceCycleResult(ResourceCycleResult::ACYCLIC);
    }
}
