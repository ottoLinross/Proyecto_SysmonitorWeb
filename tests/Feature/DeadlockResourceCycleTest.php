<?php

namespace Tests\Feature;

use App\Services\Deadlock\ResourceCycleResult;
use Tests\TestCase;

class DeadlockResourceCycleTest extends TestCase
{
    private function data(): array
    {
        return ['n' => 1, 'm' => 2, 'allocation' => [[2, 0]], 'max' => [[4, 2]], 'available' => [2, 2], 'process' => 1, 'request' => [1, 0]];
    }

    public function test_need_does_not_trigger_a_cycle_without_explicit_request(): void
    {
        $this->get('/interbloqueos')->assertOk()->assertDontSeeText('Detección de ciclos dirigidos');
        $this->post('/interbloqueos', $this->data())->assertOk()
            ->assertSeeText('No existe una solicitud explícita activa para analizar')
            ->assertViewHas('cycleResult', fn ($result) => $result->status === ResourceCycleResult::NO_REQUEST && $result->path === []);
    }

    public function test_cycle_is_displayed_without_claiming_deadlock_and_preserves_all_results(): void
    {
        $this->post('/interbloqueos/solicitudes', $this->data())->assertOk()
            ->assertSeeText('Se detectó al menos un ciclo.')->assertSeeText('P1 → R1 → P1')
            ->assertSeeText('no demuestra por sí solo un interbloqueo')
            ->assertSeeText('instantánea educativa anterior a su evaluación')
            ->assertSeeText('Need — Matriz de necesidad')->assertSeeText('Análisis de seguridad')
            ->assertSeeText('Pasos reales del análisis de seguridad')->assertSeeText('Resultado de la solicitud')
            ->assertSeeText('Estado simulado concedido')->assertSeeText('Nodos del grafo')
            ->assertViewHas('cycleResult', fn ($result) => $result->path === ['P1', 'R1', 'P1'])
            ->assertViewHas('scenario', fn ($scenario) => $scenario->allocation === [[2, 0]]);
    }

    public function test_explicit_request_can_be_acyclic_even_when_need_could_close_a_cycle(): void
    {
        $data = $this->data();
        $data['request'] = [0, 1];
        $this->post('/interbloqueos/solicitudes', $data)->assertOk()
            ->assertSeeText('No se detectaron ciclos.')
            ->assertViewHas('cycleResult', fn ($result) => $result->status === ResourceCycleResult::ACYCLIC);
    }

    public function test_zero_or_malformed_request_has_no_active_edges(): void
    {
        $data = $this->data();
        $data['request'] = [0, 0];
        $this->post('/interbloqueos/solicitudes', $data)->assertOk()
            ->assertSeeText('No existe una solicitud explícita activa para analizar');
        $data['request'] = '<script>alert(1)</script>';
        $this->post('/interbloqueos/solicitudes', $data)->assertUnprocessable()
            ->assertSeeText('No existe una solicitud explícita activa para analizar')
            ->assertSee('&lt;script&gt;', false)->assertDontSee('<script>', false);
    }

    public function test_cycle_path_is_escaped(): void
    {
        $cycleResult = new ResourceCycleResult(ResourceCycleResult::CYCLE, ['<script>alert(1)</script>', 'R1', '<script>alert(1)</script>']);
        $this->view('deadlocks.partials.resource-cycle', compact('cycleResult'))
            ->assertSee('&lt;script&gt;', false)->assertDontSee('<script>', false);
    }
}
