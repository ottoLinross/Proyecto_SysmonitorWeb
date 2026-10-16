<?php

namespace Tests\Feature;

use App\Services\Deadlock\ScenarioInput;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeadlockResourceRequestTest extends TestCase
{
    private function data(): array
    {
        return ['n' => '2', 'm' => '1', 'allocation' => '[[1],[1]]', 'max' => '[[3],[2]]', 'available' => '[1]', 'process' => '2', 'request' => '[1]'];
    }

    public function test_validated_scenario_displays_a_request_form(): void
    {
        $this->post('/interbloqueos', $this->data())->assertOk()
            ->assertSee('action="'.route('deadlocks.request').'"', false)
            ->assertSee('<option value="1"', false)->assertSee('<option value="2"', false)
            ->assertSeeText('Simulación educativa')->assertSee('name="_token"', false);
    }

    public function test_granted_request_displays_simulation_and_keeps_original_analysis_and_steps(): void
    {
        DB::shouldReceive('connection')->never();
        $this->post('/interbloqueos/solicitudes', $this->data())->assertOk()
            ->assertSeeText('Proceso: P2.')->assertSeeText('Request: [1]')
            ->assertSeeText('Concesión segura únicamente en la simulación.')
            ->assertSeeText('Estado simulado concedido')->assertSeeText('Allocation simulada')
            ->assertSeeText('Available simulado')->assertSeeText('Need simulada')
            ->assertSeeText('Pasos reales del análisis de seguridad')
            ->assertViewHas('scenario', fn ($value) => $value->allocation === [[1], [1]] && $value->available === [1])
            ->assertViewHas('need', [[2], [1]])
            ->assertViewHas('safety', fn ($value) => $value->steps[0]->workBefore === [1] && $value->safeSequence === [1, 0])
            ->assertViewHas('requestResult', fn ($value) => $value->decision === 'granted' && $value->simulated->allocation === [[1], [2]] && $value->simulated->available === [0] && $value->safety->need === [[2], [0]])
            ->assertSee('name="allocation" value="[[1],[1]]"', false)
            ->assertSee('name="available" value="[1]"', false);
    }

    public static function decisions(): iterable
    {
        yield 'need exceeded' => ['2', '[2]', 'invalid', 'Solicitud inválida: Request supera Need'];
        yield 'insufficient resources' => ['1', '[2]', 'wait', 'Recursos insuficientes: la solicitud debe esperar'];
        yield 'unsafe simulation' => ['1', '[1]', 'unsafe', 'Concesión denegada: el estado temporal es inseguro'];
    }

    #[DataProvider('decisions')]
    public function test_non_granted_decisions_are_distinct_and_keep_the_original(string $process, string $request, string $decision, string $message): void
    {
        $data = $this->data();
        $data['process'] = $process;
        $data['request'] = $request;
        $this->post('/interbloqueos/solicitudes', $data)->assertOk()->assertSeeText($message)
            ->assertDontSeeText('Estado simulado concedido')
            ->assertViewHas('scenario', fn ($value) => $value->allocation === [[1], [1]] && $value->available === [1])
            ->assertViewHas('requestResult', fn ($value) => $value->decision === $decision && $value->simulated === null);
    }

    public function test_malformed_request_retains_original_scenario_and_reports_errors(): void
    {
        $data = $this->data();
        $data['request'] = '<script>alert(1)</script>';
        $this->post('/interbloqueos/solicitudes', $data)->assertUnprocessable()
            ->assertSeeText('Solicitud inválida')->assertSeeText('El campo request debe ser una lista JSON válida.')
            ->assertSee('&lt;script&gt;', false)->assertDontSee('<script>', false)
            ->assertSeeText('Pasos reales del análisis de seguridad')
            ->assertViewHas('scenario', fn ($value) => $value->allocation === [[1], [1]])
            ->assertViewMissing('requestResult');
    }

    public function test_nonexistent_process_and_wrong_dimensions_receive_json_validation_errors(): void
    {
        $data = $this->data();
        $data['process'] = 3;
        $this->postJson('/interbloqueos/solicitudes', $data)->assertUnprocessable()->assertJsonValidationErrors('process');
        $data['process'] = 1;
        $data['request'] = [0, 0];
        $this->postJson('/interbloqueos/solicitudes', $data)->assertUnprocessable()->assertJsonValidationErrors('request');
    }

    public function test_original_body_prevents_ambiguous_process_numbers(): void
    {
        $data = $this->data();
        $data['process'] = ' 1 ';
        $this->call('POST', '/interbloqueos/solicitudes', [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'HTTP_ACCEPT' => 'application/json'], http_build_query($data))
            ->assertUnprocessable()->assertJsonValidationErrors('process');
    }

    public function test_structured_json_request_and_canonical_strings_are_supported(): void
    {
        $this->postJson('/interbloqueos/solicitudes', [
            'n' => 1, 'm' => 2, 'allocation' => [[0, 0]], 'max' => [[2, 3]], 'available' => [2, 3], 'process' => '1', 'request' => ['2', '3'],
        ])->assertOk()->assertViewHas('requestResult', fn ($value) => $value->decision === 'granted' && $value->request->vector === [2, 3] && $value->simulated->available === [0, 0]);
    }

    public function test_excessive_request_payload_is_rejected_without_flashing_input(): void
    {
        $data = $this->data();
        $data['request'] = str_repeat('x', ScenarioInput::MAX_PAYLOAD_BYTES + 1);
        $this->post('/interbloqueos/solicitudes', $data)->assertStatus(413)->assertSessionMissing('_old_input');
    }

    public function test_request_route_revalidates_the_submitted_scenario(): void
    {
        $data = $this->data();
        $data['allocation'] = '[[9],[1]]';
        $this->postJson('/interbloqueos/solicitudes', $data)->assertUnprocessable()->assertJsonValidationErrors('allocation.0.0');
    }
}
