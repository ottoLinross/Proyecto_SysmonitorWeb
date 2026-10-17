<?php

namespace Tests\Feature;

use App\Services\Deadlock\ResourceGraph;
use Tests\TestCase;

class DeadlockResourceGraphTest extends TestCase
{
    private function data(): array
    {
        return ['n' => 1, 'm' => 2, 'allocation' => [[2, 0]], 'max' => [[3, 0]], 'available' => [1, 0], 'process' => 1, 'request' => [1, 0]];
    }

    public function test_graph_is_only_shown_after_validation_and_preserves_existing_sections(): void
    {
        $this->get('/interbloqueos')->assertOk()->assertDontSeeText('Nodos del grafo');
        $this->post('/interbloqueos', $this->data())->assertOk()
            ->assertSeeText('Grafo de asignación de recursos')->assertSeeText('Nodos del grafo')
            ->assertSeeText('Aristas dirigidas del grafo')->assertSeeText('Need — Necesidad potencial')
            ->assertSeeText('no necesariamente una solicitud activa')
            ->assertSeeText('Este grafo no confirma un interbloqueo')
            ->assertSeeText('Pasos reales del análisis de seguridad')->assertSeeText('Evaluar solicitud de recursos')
            ->assertSee('aria-labelledby="graph-nodes-caption"', false)
            ->assertViewHas('graph', fn ($graph) => count($graph->nodes) === 3 && count($graph->edges) === 2);
    }

    public function test_request_is_separate_and_graph_keeps_original_allocation_after_grant(): void
    {
        $this->post('/interbloqueos/solicitudes', $this->data())->assertOk()
            ->assertSeeText('Request — Solicitud explícita')->assertSeeText('Estado simulado concedido')
            ->assertViewHas('graph', fn ($graph) => $graph->edges === [
                ['source' => 'R1', 'target' => 'P1', 'type' => 'allocation', 'quantity' => 2],
                ['source' => 'P1', 'target' => 'R1', 'type' => 'need', 'quantity' => 1],
                ['source' => 'P1', 'target' => 'R1', 'type' => 'request', 'quantity' => 1],
            ]);
    }

    public function test_rejected_explicit_request_is_still_identified(): void
    {
        $data = $this->data();
        $data['request'] = [2, 0];
        $this->post('/interbloqueos/solicitudes', $data)->assertOk()
            ->assertSeeText('Request — Solicitud explícita')->assertSeeText('Request supera Need')
            ->assertViewHas('graph', fn ($graph) => $graph->edges[2]['quantity'] === 2);
    }

    public function test_malformed_request_keeps_graph_without_explicit_edges_and_escapes_content(): void
    {
        $data = $this->data();
        $data['request'] = '<script>alert(1)</script>';
        $this->post('/interbloqueos/solicitudes', $data)->assertUnprocessable()
            ->assertSeeText('Nodos del grafo')->assertSee('&lt;script&gt;', false)->assertDontSee('<script>', false)
            ->assertViewHas('graph', fn ($graph) => array_column($graph->edges, 'type') === ['allocation', 'need']);
    }

    public function test_empty_graph_has_accessible_description(): void
    {
        $this->post('/interbloqueos', ['n' => 1, 'm' => 1, 'allocation' => [[0]], 'max' => [[0]], 'available' => [0]])
            ->assertOk()->assertSeeText('Sin aristas: todas las cantidades son cero.')
            ->assertViewHas('graph', fn ($graph) => count($graph->nodes) === 2 && $graph->edges === []);
    }

    public function test_graph_partial_escapes_node_and_edge_attributes_as_text(): void
    {
        $unsafe = '<script>alert(1)</script>';
        $graph = new ResourceGraph([['id' => $unsafe, 'type' => 'process']], [['source' => $unsafe, 'target' => 'R1', 'type' => 'need', 'quantity' => 1]]);
        $this->view('deadlocks.partials.resource-graph', compact('graph'))
            ->assertSee('&lt;script&gt;', false)->assertDontSee('<script>', false);
    }
}
