<?php

namespace Tests\Unit;

use App\Services\Deadlock\NeedCalculator;
use App\Services\Deadlock\ResourceCycleDetector;
use App\Services\Deadlock\ResourceCycleResult;
use App\Services\Deadlock\ResourceGraph;
use App\Services\Deadlock\ResourceGraphBuilder;
use App\Services\Deadlock\ResourceRequestInput;
use App\Services\Deadlock\ScenarioInput;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeadlockResourceCycleTest extends TestCase
{
    private function graph(array $edges): ResourceGraph
    {
        return new ResourceGraph(array_map(fn ($id) => ['id' => $id, 'type' => str_starts_with($id, 'P') ? 'process' : 'resource'], ['P1', 'P2', 'P3', 'R1', 'R2', 'R3']), array_map(fn ($edge) => ['source' => $edge[0], 'target' => $edge[1], 'type' => $edge[2], 'quantity' => $edge[3] ?? 1], $edges));
    }

    public static function cases(): iterable
    {
        yield 'empty' => [[], ResourceCycleResult::NO_REQUEST, []];
        yield 'potential need cycle excluded' => [[['R1', 'P1', 'allocation'], ['P1', 'R1', 'need']], ResourceCycleResult::NO_REQUEST, []];
        yield 'zero request excluded' => [[['R1', 'P1', 'allocation'], ['P1', 'R1', 'request', 0]], ResourceCycleResult::NO_REQUEST, []];
        yield 'acyclic request' => [[['P1', 'R1', 'request'], ['R1', 'P2', 'allocation']], ResourceCycleResult::ACYCLIC, []];
        yield 'need cannot close active path' => [[['P1', 'R1', 'request'], ['R1', 'P2', 'allocation'], ['P2', 'R2', 'need'], ['R2', 'P1', 'allocation']], ResourceCycleResult::ACYCLIC, []];
        yield 'closed directed cycle' => [[['P1', 'R2', 'request'], ['R2', 'P2', 'allocation'], ['P2', 'R1', 'request'], ['R1', 'P1', 'allocation']], ResourceCycleResult::CYCLE, ['P1', 'R2', 'P2', 'R1', 'P1']];
        yield 'disconnected cycle' => [[['P1', 'R1', 'request'], ['P3', 'R3', 'request', 4], ['R3', 'P3', 'allocation', 2]], ResourceCycleResult::CYCLE, ['P3', 'R3', 'P3']];
        yield 'first of multiple cycles' => [[['P1', 'R2', 'request'], ['P1', 'R1', 'request'], ['R1', 'P1', 'allocation'], ['R2', 'P1', 'allocation']], ResourceCycleResult::CYCLE, ['P1', 'R2', 'P1']];
        yield 'finished node is not a back edge' => [[['P1', 'R1', 'request'], ['P2', 'R1', 'request'], ['R1', 'P3', 'allocation']], ResourceCycleResult::ACYCLIC, []];
        yield 'zero allocation does not close cycle' => [[['P1', 'R1', 'request'], ['R1', 'P1', 'allocation', 0]], ResourceCycleResult::ACYCLIC, []];
    }

    #[DataProvider('cases')]
    public function test_cycle_detection_is_deterministic_and_does_not_mutate_graph(array $edges, string $status, array $path): void
    {
        $graph = $this->graph($edges);
        $before = serialize($graph);
        $detector = new ResourceCycleDetector;
        $result = $detector->detect($graph);
        $this->assertSame($status, $result->status);
        $this->assertSame($path, $result->path);
        $this->assertEquals($result, $detector->detect($graph));
        $this->assertSame($before, serialize($graph));
        foreach (array_slice($result->path, 1) as $i => $target) {
            $this->assertNotEmpty(array_filter($graph->edges, fn ($edge) => $edge['source'] === $result->path[$i] && $edge['target'] === $target && in_array($edge['type'], ['allocation', 'request'], true) && $edge['quantity'] > 0));
        }
    }

    public function test_reuses_builder_and_validated_scenario_with_multiple_instances(): void
    {
        $input = new ScenarioInput;
        $scenario = $input->validate(['n' => 1, 'm' => 1, 'allocation' => [[2]], 'max' => [[5]], 'available' => [3]]);
        $builder = new ResourceGraphBuilder(new NeedCalculator);
        $request = (new ResourceRequestInput($input))->validate(['process' => 1, 'request' => [2]], $scenario);
        $detector = new ResourceCycleDetector;
        $this->assertSame(ResourceCycleResult::NO_REQUEST, $detector->detect($builder->build($scenario))->status);
        $this->assertSame(['P1', 'R1', 'P1'], $detector->detect($builder->build($scenario, $request))->path);
        $this->assertSame(ResourceCycleResult::NO_REQUEST, $detector->detect($builder->build($scenario))->status);
    }
}
