<?php

namespace Tests\Unit;

use App\Services\Deadlock\NeedCalculator;
use App\Services\Deadlock\ResourceGraphBuilder;
use App\Services\Deadlock\ResourceRequestInput;
use App\Services\Deadlock\ScenarioInput;
use Tests\TestCase;

class DeadlockResourceGraphTest extends TestCase
{
    public function test_nodes_edges_quantities_and_order_preserve_original_scenario(): void
    {
        $input = new ScenarioInput;
        $scenario = $input->validate(['n' => 2, 'm' => 2, 'allocation' => [[2, 0], [0, 3]], 'max' => [[5, 0], [1, 3]], 'available' => [4, 1]]);
        $request = (new ResourceRequestInput($input))->validate(['process' => 1, 'request' => [1, 0]], $scenario);
        $before = serialize([$scenario, $request]);
        $builder = new ResourceGraphBuilder(new NeedCalculator);
        $graph = $builder->build($scenario, $request);

        $this->assertSame([
            ['id' => 'P1', 'type' => 'process'], ['id' => 'P2', 'type' => 'process'],
            ['id' => 'R1', 'type' => 'resource', 'available' => 4, 'total' => 6],
            ['id' => 'R2', 'type' => 'resource', 'available' => 1, 'total' => 4],
        ], $graph->nodes);
        $this->assertSame([
            ['source' => 'R1', 'target' => 'P1', 'type' => 'allocation', 'quantity' => 2],
            ['source' => 'P1', 'target' => 'R1', 'type' => 'need', 'quantity' => 3],
            ['source' => 'P1', 'target' => 'R1', 'type' => 'request', 'quantity' => 1],
            ['source' => 'P2', 'target' => 'R1', 'type' => 'need', 'quantity' => 1],
            ['source' => 'R2', 'target' => 'P2', 'type' => 'allocation', 'quantity' => 3],
        ], $graph->edges);
        $this->assertEquals($graph, $builder->build($scenario, $request));
        $this->assertSame($before, serialize([$scenario, $request]));
        $this->assertNotContains('request', array_column($builder->build($scenario)->edges, 'type'));
    }

    public function test_zero_quantities_produce_nodes_without_edges(): void
    {
        $input = new ScenarioInput;
        $scenario = $input->validate(['n' => 1, 'm' => 1, 'allocation' => [[0]], 'max' => [[0]], 'available' => [0]]);
        $request = (new ResourceRequestInput($input))->validate(['process' => 1, 'request' => [0]], $scenario);
        $graph = (new ResourceGraphBuilder(new NeedCalculator))->build($scenario, $request);
        $this->assertCount(2, $graph->nodes);
        $this->assertSame([], $graph->edges);
    }

    public function test_maximum_dimensions_follow_numeric_order_and_keep_aggregated_quantities(): void
    {
        $matrix = array_fill(0, 20, array_fill(0, 20, ScenarioInput::MAX_VALUE));
        $scenario = (new ScenarioInput)->validate(['n' => 20, 'm' => 20, 'allocation' => $matrix, 'max' => $matrix, 'available' => array_fill(0, 20, ScenarioInput::MAX_VALUE)]);
        $graph = (new ResourceGraphBuilder(new NeedCalculator))->build($scenario);
        $this->assertSame(array_merge(array_map(fn ($i) => 'P'.$i, range(1, 20)), array_map(fn ($i) => 'R'.$i, range(1, 20))), array_column($graph->nodes, 'id'));
        $this->assertCount(400, $graph->edges);
        $this->assertSame(21000000, $graph->nodes[20]['total']);
        $this->assertSame(['source' => 'R20', 'target' => 'P20', 'type' => 'allocation', 'quantity' => 1000000], $graph->edges[399]);
    }
}
