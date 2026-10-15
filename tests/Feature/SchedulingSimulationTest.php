<?php

namespace Tests\Feature;

use App\Services\Scheduling\SchedulingSimulation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SchedulingSimulationTest extends TestCase
{
    #[DataProvider('algorithms')]
    public function test_blade_renders_backend_metrics_and_every_gantt_segment(string $algorithm): void
    {
        DB::shouldReceive('connection')->never();
        $response = $this->post('/planificacion/simular', $this->payload($algorithm));
        $response->assertOk()->assertViewIs('scheduling.index')
            ->assertSeeText('Diagrama de Gantt')->assertSeeText('CPU ociosa (idle)')
            ->assertSeeText('Espera promedio:')->assertSeeText(SchedulingSimulation::ALGORITHMS[$algorithm]);
        $result = $response->viewData('simulation');
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $rows = $xpath->query('//table[@id="gantt-segments"]/tbody/tr');
        $bars = $xpath->query('//ol[@class="gantt-track"]/li');
        $this->assertSame(count($result['segments']), $rows->length);
        $this->assertSame($rows->length, $bars->length);
        foreach ($result['segments'] as $index => $segment) {
            $row = $rows->item($index);
            $this->assertSame($segment['type'], $row->getAttribute('data-segment-type'));
            $this->assertSame((string) $segment['start_time'], $row->getAttribute('data-start'));
            $this->assertSame((string) $segment['end_time'], $row->getAttribute('data-end'));
            $this->assertStringContainsString($segment['process_id'] ?? 'idle', $row->textContent);
        }
        $metricRows = $xpath->query('//tr[@data-process-id]');
        foreach ($result['metrics']['processes'] as $index => $metric) {
            $cells = $metricRows->item($index)->getElementsByTagName('td');
            $this->assertSame($metric['id'], $cells->item(0)->textContent);
            $this->assertSame((string) $metric['completion_time'], $cells->item(1)->textContent);
            $this->assertSame((string) $metric['turnaround_time'], $cells->item(2)->textContent);
            $this->assertSame((string) $metric['waiting_time'], $cells->item(3)->textContent);
        }
    }

    public static function algorithms(): array
    {
        return array_map(fn (string $key): array => [$key], array_keys(SchedulingSimulation::ALGORITHMS));
    }

    #[DataProvider('invalidOptions')]
    public function test_invalid_simulation_options_are_rejected(array $options, string $field): void
    {
        $this->post('/planificacion/simular', array_replace($this->payload('round_robin'), $options))
            ->assertSessionHasErrors($field);
    }

    public static function invalidOptions(): array
    {
        return [
            [['algorithm' => 'unknown'], 'algorithm'], [['algorithm' => null], 'algorithm'],
            [['algorithm' => ['fcfs']], 'algorithm'], [['quantum' => null], 'quantum'],
            [['quantum' => 0], 'quantum'], [['quantum' => -1], 'quantum'],
            [['quantum' => '1.5'], 'quantum'], [['quantum' => true], 'quantum'],
        ];
    }

    public function test_processes_are_revalidated_on_simulation_and_quantum_is_only_required_for_rr(): void
    {
        $payload = $this->payload('fcfs');
        unset($payload['quantum']);
        $this->post('/planificacion/simular', $payload)->assertOk();
        $payload['processes'][0]['burst_time'] = 0;
        $this->post('/planificacion/simular', $payload)->assertSessionHasErrors('processes.0.burst_time');
    }

    public function test_simulation_names_are_escaped_in_metrics_and_gantt(): void
    {
        $payload = $this->payload('fcfs');
        $payload['processes'][0]['id'] = '<script>x</script>';
        $this->post('/planificacion/simular', $payload)->assertOk()
            ->assertSee('&lt;script&gt;x&lt;/script&gt;', false)->assertDontSee('<script>x</script>', false);
    }

    public function test_validated_batch_exposes_simulation_form_with_csrf_and_process_data(): void
    {
        $this->post('/planificacion/manual', $this->payload('fcfs'))->assertOk()
            ->assertSee(route('scheduling.simulate'), false)
            ->assertSee('name="processes[0][id]" value="A"', false)
            ->assertSee('name="algorithm"', false)->assertSee('name="_token"', false);
    }

    public function test_simulation_requires_csrf(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        $this->post('/planificacion/simular', $this->payload('fcfs'))->assertStatus(419);
    }

    private function payload(string $algorithm): array
    {
        return ['algorithm' => $algorithm, 'quantum' => '2', 'processes' => [
            ['id' => 'A', 'arrival_time' => 2, 'burst_time' => 5, 'priority' => 5],
            ['id' => 'B', 'arrival_time' => 3, 'burst_time' => 1, 'priority' => 1],
        ]];
    }
}
