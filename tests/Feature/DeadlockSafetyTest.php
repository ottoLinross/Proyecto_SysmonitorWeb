<?php

namespace Tests\Feature;

use App\Services\Deadlock\BankerSafetyAnalyzer;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeadlockSafetyTest extends TestCase
{
    public function test_safe_scenario_displays_the_deterministic_sequence_without_database_access(): void
    {
        DB::shouldReceive('connection')->never();
        $this->post('/interbloqueos', [
            'n' => '3', 'm' => '1', 'allocation' => '[[1],[1],[1]]', 'max' => '[[2],[1],[1]]', 'available' => '[0]',
        ])->assertOk()->assertSeeText('Estado seguro')->assertSeeText('P2 → P1 → P3')
            ->assertDontSeeText('Estado inseguro')
            ->assertViewHas('safety', fn ($result) => $result->isSafe && $result->safeSequence === [1, 0, 2])
            ->assertViewHas('need', [[1], [0], [0]]);
    }

    public function test_unsafe_scenario_identifies_unfinished_processes_without_claiming_deadlock(): void
    {
        $this->postJson('/interbloqueos', [
            'n' => 3, 'm' => 1, 'allocation' => [[1], [1], [0]], 'max' => [[1], [3], [3]], 'available' => [0],
        ])->assertOk()->assertSeeText('Estado inseguro')->assertSeeText('P2, P3')
            ->assertSeeText('Un estado inseguro no confirma por sí solo la existencia de un interbloqueo.')
            ->assertDontSeeText('Secuencia segura:')
            ->assertViewHas('safety', fn ($result) => ! $result->isSafe && $result->safeSequence === null && $result->unfinished === [1, 2]);
    }

    public function test_initial_page_and_invalid_inputs_do_not_run_the_analysis(): void
    {
        $this->mock(BankerSafetyAnalyzer::class)->shouldNotReceive('analyze');
        $this->get('/interbloqueos')->assertOk()->assertViewMissing('safety')->assertDontSeeText('Análisis de seguridad');
        $this->from('/interbloqueos')->post('/interbloqueos', [
            'n' => 1, 'm' => 1, 'allocation' => [[2]], 'max' => [[1]], 'available' => [0],
        ])->assertRedirect('/interbloqueos')->assertSessionHasErrors('allocation.0.0');
        $this->get('/interbloqueos')->assertOk()->assertViewMissing('safety')->assertDontSeeText('Análisis de seguridad');
    }
}
