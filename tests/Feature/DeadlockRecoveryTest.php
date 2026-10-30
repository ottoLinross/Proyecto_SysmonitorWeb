<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Deadlock\ResourceCycleResult;
use App\Services\Deadlock\ScenarioInput;
use App\Services\System\ProcessSignalService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeadlockRecoveryTest extends TestCase
{
    private function data(): array
    {
        return ['n' => 2, 'm' => 1, 'allocation' => [[1], [1]], 'max' => [[2], [2]], 'available' => [0], 'strategy' => 'terminate', 'recovery_process' => 1];
    }

    public function test_form_requires_a_valid_scenario_and_has_csrf_and_strategy_options(): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'role' => User::ROLE_ADMIN]), 'web');

        $this->get('/interbloqueos')->assertOk()->assertDontSeeText('Recuperación conceptual');
        $this->post('/interbloqueos', $this->data())->assertOk()
            ->assertSee('action="'.route('deadlocks.recovery').'"', false)
            ->assertSee('name="_token"', false)->assertSee('value="terminate"', false)
            ->assertSee('value="victim"', false)->assertSee('value="preemption"', false)
            ->assertSeeText('no acciones ejecutadas')->assertSeeText('ni exige recuperación');
    }

    public function test_manual_recovery_keeps_original_analysis_and_only_changes_a_copy(): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'role' => User::ROLE_ADMIN]), 'web');

        DB::shouldReceive('connection')->never();
        $this->app->bind(ProcessSignalService::class, fn () => throw new \RuntimeException('Recovery must not resolve real process controls'));
        $this->post('/interbloqueos/recuperacion', $this->data())->assertOk()
            ->assertSeeText('Estado inseguro')->assertSeeText('Estado temporal seguro según el banquero.')
            ->assertSeeText('Proceso retirado conceptualmente: P1.')
            ->assertSeeText('Secuencia segura temporal: P2.')
            ->assertSeeText('Unidades que se liberarían, en orden R1 a R1: [1].')
            ->assertSeeText('Allocation temporal de procesos activos')->assertSeeText('Available temporal')
            ->assertSeeText('Grafo de asignación de recursos')->assertSeeText('Detección de ciclos dirigidos')
            ->assertSeeText('Evaluar solicitud de recursos')->assertSeeText('Ejecución paso a paso')
            ->assertViewHas('scenario', fn ($scenario) => $scenario->allocation === [[1], [1]] && $scenario->available === [0])
            ->assertViewHas('safety', fn ($safety) => ! $safety->isSafe && $safety->unfinished === [0, 1])
            ->assertViewHas('recoveryResult', fn ($result) => $result->simulated->allocation === [[1]] && $result->simulated->available === [1] && $result->activeProcesses === [1])
            ->assertViewHas('cycleResult', fn ($result) => $result->status === ResourceCycleResult::NO_REQUEST);
    }

    public function test_automatic_selection_ignores_manual_selector_and_request_context_is_preserved(): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'role' => User::ROLE_ADMIN]), 'web');

        $data = $this->data();
        $data['strategy'] = 'victim';
        $data['recovery_process'] = 2;
        $data['process'] = 1;
        $data['request'] = [1];
        $before = $this->post('/interbloqueos/solicitudes', $data)->assertOk();
        $this->post('/interbloqueos/recuperacion', $data)->assertOk()
            ->assertSeeText('Selección automática de víctima')->assertSeeText('Proceso retirado conceptualmente: P1.')
            ->assertSeeText('Resultado de la solicitud')->assertSeeText('Recorrido cerrado del ciclo: P1 → R1 → P1')
            ->assertViewHas('requestResult', fn ($result) => $result == $before->viewData('requestResult'))
            ->assertViewHas('safety', fn ($result) => $result == $before->viewData('safety'))
            ->assertViewHas('graph', fn ($result) => $result == $before->viewData('graph'))
            ->assertViewHas('cycleResult', fn ($result) => $result == $before->viewData('cycleResult'))
            ->assertSee('name="request" value="[1]"', false);
    }

    public function test_conceptual_preemption_has_limitations_and_no_simulated_state(): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'role' => User::ROLE_ADMIN]), 'web');

        $data = $this->data();
        $data['strategy'] = 'preemption';
        $this->post('/interbloqueos/recuperacion', $data)->assertOk()
            ->assertSeeText('No todos los recursos admiten retiro seguro')
            ->assertSeeText('inanición')->assertSeeText('rollback')
            ->assertDontSeeText('Allocation temporal de procesos activos')
            ->assertViewHas('recoveryResult', fn ($result) => $result->simulated === null && $result->safety === null);
    }

    public function test_last_process_is_not_left_pending_or_shown_in_a_fabricated_sequence(): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'role' => User::ROLE_ADMIN]), 'web');

        $this->post('/interbloqueos/recuperacion', ['n' => 1, 'm' => 1, 'allocation' => [[1]], 'max' => [[2]], 'available' => [0], 'strategy' => 'terminate', 'recovery_process' => 1])
            ->assertOk()->assertSeeText('No quedan procesos activos; la secuencia temporal está vacía.')
            ->assertViewHas('recoveryResult', fn ($result) => $result->safety->unfinished === [] && $result->safety->safeSequence === []);
    }

    public function test_strategy_can_leave_the_temporary_state_unsafe(): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'role' => User::ROLE_ADMIN]), 'web');

        $data = $this->data();
        $data['allocation'] = [[0], [0]];
        $this->post('/interbloqueos/recuperacion', $data)->assertOk()
            ->assertSeeText('Esta estrategia no produjo un estado seguro.')
            ->assertSeeText('Pendientes temporales: P2.');
    }

    public static function invalidChoices(): iterable
    {
        yield 'missing strategy' => [null, 1, 'strategy'];
        yield 'unknown strategy' => ['kill', 1, 'strategy'];
        yield 'array strategy' => [['terminate'], 1, 'strategy'];
        yield 'missing process' => ['terminate', null, 'process'];
        yield 'zero process' => ['terminate', 0, 'process'];
        yield 'out of range' => ['terminate', 3, 'process'];
        yield 'noncanonical' => ['terminate', '01', 'process'];
        yield 'array process' => ['terminate', [1], 'process'];
    }

    #[DataProvider('invalidChoices')]
    public function test_invalid_strategy_or_process_is_rejected(mixed $strategy, mixed $process, string $field): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'role' => User::ROLE_ADMIN]), 'web');

        $data = $this->data();
        $data['strategy'] = $strategy;
        $data['recovery_process'] = $process;
        $this->postJson('/interbloqueos/recuperacion', $data)->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public function test_html_error_retains_original_results_and_escapes_input(): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'role' => User::ROLE_ADMIN]), 'web');

        $data = $this->data();
        $data['strategy'] = '<script>alert(1)</script>';
        $this->post('/interbloqueos/recuperacion', $data)->assertUnprocessable()
            ->assertSeeText('Selecciona una estrategia de recuperación válida.')
            ->assertSeeText('Grafo de asignación de recursos')->assertDontSee('<script>', false)
            ->assertViewMissing('recoveryResult');
    }

    public function test_granted_request_and_original_safe_sequence_are_kept_separate_from_recovery(): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'role' => User::ROLE_ADMIN]), 'web');

        $data = $this->data();
        $data['available'] = [1];
        $data['process'] = 1;
        $data['request'] = [1];
        $data['recovery_process'] = 2;
        $this->post('/interbloqueos/recuperacion', $data)->assertOk()
            ->assertSeeText('Estado simulado concedido')->assertSeeText('Secuencia segura:')
            ->assertSeeText('Secuencia segura temporal: P1.')
            ->assertViewHas('safety', fn ($result) => $result->safeSequence === [0, 1])
            ->assertViewHas('requestResult', fn ($result) => $result->simulated->allocation === [[2], [1]] && $result->simulated->available === [0])
            ->assertViewHas('recoveryResult', fn ($result) => $result->simulated->allocation === [[1]] && $result->simulated->available === [2]);
    }

    public function test_raw_body_does_not_accept_a_process_with_surrounding_spaces(): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'role' => User::ROLE_ADMIN]), 'web');

        $data = $this->data();
        $data['recovery_process'] = ' 1 ';
        $this->call('POST', '/interbloqueos/recuperacion', [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'HTTP_ACCEPT' => 'application/json'], http_build_query($data))
            ->assertUnprocessable()->assertJsonValidationErrors('process');
    }

    public function test_revalidates_original_scenario_request_context_and_payload_limits(): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'role' => User::ROLE_ADMIN]), 'web');

        $data = $this->data();
        $data['allocation'] = [[3], [1]];
        $this->postJson('/interbloqueos/recuperacion', $data)->assertUnprocessable()->assertJsonValidationErrors('allocation.0.0');
        $data = $this->data();
        $data['process'] = 1;
        $data['request'] = [1, 1];
        $this->postJson('/interbloqueos/recuperacion', $data)->assertUnprocessable()->assertJsonValidationErrors('request');
        $data = $this->data();
        $data['strategy'] = str_repeat('x', ScenarioInput::MAX_PAYLOAD_BYTES + 1);
        $this->post('/interbloqueos/recuperacion', $data)->assertStatus(413)->assertSessionMissing('_old_input');
    }
}
