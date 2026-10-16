<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeadlockSafetyStepsTest extends TestCase
{
    private function tableRows(string $html): array
    {
        preg_match('/<caption>Pasos reales del análisis de seguridad<\/caption>.*?<tbody>(.*?)<\/tbody>/s', $html, $body);
        preg_match_all('/<tr>(.*?)<\/tr>/s', $body[1] ?? '', $rows);

        return array_map(function ($row) {
            preg_match_all('/<(?:td|th)\b[^>]*>(.*?)<\/(?:td|th)>/s', $row, $cells);

            return array_map(fn ($cell) => trim(strip_tags($cell)), $cells[1]);
        }, $rows[1]);
    }

    public function test_safe_scenario_renders_all_actual_steps_and_preserves_existing_results(): void
    {
        DB::shouldReceive('connection')->never();
        $response = $this->post('/interbloqueos', [
            'n' => 3, 'm' => 2, 'allocation' => '[[1,2],[2,0],[0,1]]',
            'max' => '[[3,2],[2,1],[1,2]]', 'available' => '[0,1]',
        ])->assertOk()->assertSeeText('Estado seguro')->assertSeeText('P2 → P1 → P3')
            ->assertSeeText('Allocation')->assertSeeText('Max')->assertSeeText('Available')->assertSeeText('Need — Matriz de necesidad')
            ->assertSee('<caption>Pasos reales del análisis de seguridad</caption>', false)
            ->assertSee('scope="col"', false)->assertSee('scope="row"', false)
            ->assertSee('role="region" aria-labelledby="steps-title" tabindex="0"', false);

        $this->assertSame([
            ['1', 'P2', '[0,1]', '[0,1]', '[2,0]', '[2,1]', '[false,true,false]'],
            ['2', 'P1', '[2,1]', '[2,0]', '[1,2]', '[3,3]', '[true,true,false]'],
            ['3', 'P3', '[3,3]', '[1,1]', '[0,1]', '[3,4]', '[true,true,true]'],
        ], $this->tableRows($response->getContent()));
    }

    public function test_unsafe_scenario_renders_only_actual_partial_progress(): void
    {
        $response = $this->postJson('/interbloqueos', [
            'n' => 3, 'm' => 1, 'allocation' => [[1], [1], [0]], 'max' => [[1], [3], [3]], 'available' => [0],
        ])->assertOk()->assertSeeText('Estado inseguro')->assertSeeText('P2, P3')
            ->assertSeeText('no constituyen una secuencia segura completa')
            ->assertSeeText('Un estado inseguro no confirma por sí solo la existencia de un interbloqueo.')
            ->assertDontSeeText('Secuencia segura:');

        $this->assertSame([
            ['1', 'P1', '[0]', '[0]', '[1]', '[1]', '[true,false,false]'],
        ], $this->tableRows($response->getContent()));
    }

    public function test_no_progress_shows_a_message_without_fabricated_steps(): void
    {
        $response = $this->post('/interbloqueos', [
            'n' => 2, 'm' => 1, 'allocation' => '[[1],[1]]', 'max' => '[[2],[2]]', 'available' => '[0]',
        ])->assertOk()->assertSeeText('Estado inseguro')->assertSeeText('P1, P2')
            ->assertSeeText('Ningún proceso pudo ejecutarse; no hay pasos registrados.')
            ->assertDontSee('<caption>Pasos reales del análisis de seguridad</caption>', false)
            ->assertViewHas('safety', fn ($result) => $result->steps === []);

        $this->assertSame([], $this->tableRows($response->getContent()));
    }

    public function test_initial_page_and_invalid_scenario_do_not_show_execution_steps(): void
    {
        $this->get('/interbloqueos')->assertOk()->assertDontSeeText('Ejecución paso a paso');
        $this->from('/interbloqueos')->post('/interbloqueos', [
            'n' => 1, 'm' => 1, 'allocation' => [[2]], 'max' => [[1]], 'available' => [0],
        ])->assertRedirect('/interbloqueos')->assertSessionHasErrors('allocation.0.0');
        $this->get('/interbloqueos')->assertOk()->assertDontSeeText('Ejecución paso a paso');
    }
}
