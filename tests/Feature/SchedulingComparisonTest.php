<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SchedulingComparisonTest extends TestCase
{
    public function test_comparison_table_renders_five_algorithms_and_known_averages_in_order(): void
    {
        DB::shouldReceive('connection')->never();
        $response = $this->post('/planificacion/comparar', $this->payload());
        $response->assertOk()->assertViewIs('scheduling.index')
            ->assertSeeText('Comparación de algoritmos')->assertSeeText('Round Robin utiliza quantum 2.')
            ->assertSeeText('Espera promedio')->assertSeeText('Retorno promedio');
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $rows = $xpath->query('//table[@id="scheduling-comparison"]/tbody/tr');
        $this->assertSame(5, $rows->length);
        $expected = [
            ['fcfs', 'FCFS', 3, 17 / 3], ['sjf', 'SJF no expropiativo', 8 / 3, 16 / 3],
            ['srtf', 'SRTF', 4 / 3, 4], ['round_robin', 'Round Robin', 2, 14 / 3],
            ['priority', 'Prioridades no expropiativo', 3, 17 / 3],
        ];
        foreach ($expected as $index => [$algorithm, $label, $waiting, $turnaround]) {
            $row = $rows->item($index);
            $cells = $row->getElementsByTagName('td');
            $this->assertSame($algorithm, $row->getAttribute('data-algorithm'));
            $this->assertSame($label, $row->getElementsByTagName('th')->item(0)->textContent);
            $this->assertSame((string) $waiting, $cells->item(0)->textContent);
            $this->assertSame((string) $turnaround, $cells->item(1)->textContent);
        }
        $this->assertSame('2', $xpath->query('//input[@name="quantum"]')->item(0)->getAttribute('value'));
    }

    public function test_user_quantum_is_applied_and_preserved_in_the_form(): void
    {
        $payload = $this->payload();
        $payload['quantum'] = '5';
        $this->post('/planificacion/comparar', $payload)->assertOk()
            ->assertSeeText('Round Robin utiliza quantum 5.')
            ->assertSee('name="quantum" type="number" min="1" step="1" value="5"', false)
            ->assertViewHas('comparison', fn (array $result): bool => $result['results'][3]['metrics']['average_waiting_time'] === 3);
    }

    #[DataProvider('invalidQuanta')]
    public function test_comparison_requires_valid_quantum_even_without_algorithm_selection(mixed $quantum): void
    {
        $this->from('/planificacion')->post('/planificacion/comparar', array_replace($this->payload(), ['quantum' => $quantum]))
            ->assertRedirect('/planificacion')->assertSessionHasErrors('quantum');
    }

    public static function invalidQuanta(): array
    {
        return [[null], [0], [-1], ['1.5'], ['abc'], [true], [[]]];
    }

    public function test_hidden_processes_are_revalidated_before_comparison(): void
    {
        $payload = $this->payload();
        $payload['processes'][1]['id'] = 'A';
        $this->post('/planificacion/comparar', $payload)->assertSessionHasErrors('processes.1.id');
        $payload['processes'][1]['id'] = 'B';
        $payload['processes'][1]['burst_time'] = 0;
        $this->post('/planificacion/comparar', $payload)->assertSessionHasErrors('processes.1.burst_time');
    }

    public function test_validated_lot_exposes_comparison_button_and_individual_gantt_still_works(): void
    {
        $this->post('/planificacion/manual', $this->payload())->assertOk()
            ->assertSee('formaction="'.route('scheduling.compare').'"', false)
            ->assertSeeText('Comparar cinco algoritmos')->assertSee('name="_token"', false);
        $this->post('/planificacion/simular', array_replace($this->payload(), ['algorithm' => 'srtf']))
            ->assertOk()->assertSeeText('Diagrama de Gantt')->assertSee('id="gantt-segments"', false)
            ->assertDontSee('id="scheduling-comparison"', false);
    }

    public function test_comparison_requires_csrf(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        $this->post('/planificacion/comparar', $this->payload())->assertStatus(419);
    }

    private function payload(): array
    {
        return ['quantum' => '2', 'processes' => [
            ['id' => 'A', 'arrival_time' => 0, 'burst_time' => 5, 'priority' => 5],
            ['id' => 'B', 'arrival_time' => 1, 'burst_time' => 2, 'priority' => 1],
            ['id' => 'C', 'arrival_time' => 2, 'burst_time' => 1, 'priority' => 3],
        ]];
    }
}
