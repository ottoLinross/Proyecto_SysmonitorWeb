<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Scheduling\ProcessInput;
use App\Services\Scheduling\RandomProcessGenerator;
use App\Services\Scheduling\SchedulingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Tests\TestCase;

class SchedulingInputTest extends TestCase
{
    public function test_manual_input_returns_typed_processes_without_database_access(): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'role' => User::ROLE_ADMIN]), 'web');

        DB::shouldReceive('connection')->never();

        $this->post('/planificacion/manual', ['processes' => [$this->process()]])
            ->assertOk()
            ->assertViewIs('scheduling.index')
            ->assertViewHas('processes', fn (array $processes): bool => count($processes) === 1
                && $processes[0] instanceof SchedulingProcess
                && $processes[0]->id === 'P1'
                && $processes[0]->arrivalTime === 0
                && $processes[0]->burstTime === 5
                && $processes[0]->priority === 1);
    }

    #[DataProvider('invalidProcesses')]
    public function test_manual_input_rejects_invalid_processes(array $changes, string $field): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'role' => User::ROLE_ADMIN]), 'web');

        $this->from('/planificacion')->post('/planificacion/manual', [
            'processes' => [array_replace($this->process(), $changes)],
        ])->assertRedirect('/planificacion')->assertSessionHasErrors('processes.0.'.$field);
    }

    public static function invalidProcesses(): array
    {
        return [
            'negative arrival' => [['arrival_time' => -1], 'arrival_time'],
            'zero burst' => [['burst_time' => 0], 'burst_time'],
            'negative burst' => [['burst_time' => -1], 'burst_time'],
            'missing id' => [['id' => null], 'id'],
            'missing arrival' => [['arrival_time' => null], 'arrival_time'],
            'missing burst' => [['burst_time' => null], 'burst_time'],
            'missing priority' => [['priority' => null], 'priority'],
            'decimal arrival' => [['arrival_time' => '1.5'], 'arrival_time'],
            'decimal burst' => [['burst_time' => '2.5'], 'burst_time'],
            'decimal priority' => [['priority' => '1.5'], 'priority'],
            'text burst' => [['burst_time' => 'abc'], 'burst_time'],
            'boolean priority' => [['priority' => true], 'priority'],
            'array arrival' => [['arrival_time' => []], 'arrival_time'],
            'priority below minimum' => [['priority' => 0], 'priority'],
            'priority above maximum' => [['priority' => 11], 'priority'],
            'arrival above maximum' => [['arrival_time' => 10001], 'arrival_time'],
            'burst above maximum' => [['burst_time' => 1001], 'burst_time'],
            'id too long' => [['id' => str_repeat('P', 33)], 'id'],
            'empty id' => [['id' => '   '], 'id'],
        ];
    }

    public function test_duplicate_ids_are_rejected(): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'role' => User::ROLE_ADMIN]), 'web');

        $this->post('/planificacion/manual', ['processes' => [$this->process(), $this->process()]])
            ->assertSessionHasErrors(['processes.0.id', 'processes.1.id']);
    }

    public function test_missing_fields_and_empty_or_excessive_batches_are_rejected(): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'role' => User::ROLE_ADMIN]), 'web');

        $this->post('/planificacion/manual', [])->assertSessionHasErrors('processes');
        $this->post('/planificacion/manual', ['processes' => []])->assertSessionHasErrors('processes');
        $this->post('/planificacion/manual', ['processes' => [[]]])
            ->assertSessionHasErrors(['processes.0.id', 'processes.0.arrival_time', 'processes.0.burst_time', 'processes.0.priority']);
        $processes = array_map(fn (int $i): array => array_replace($this->process(), ['id' => 'P'.$i]), range(1, 51));
        $this->post('/planificacion/manual', ['processes' => $processes])->assertSessionHasErrors('processes');
    }

    public function test_upper_bounds_and_html_integer_strings_are_accepted(): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'role' => User::ROLE_ADMIN]), 'web');

        $this->post('/planificacion/manual', ['processes' => [[
            'id' => str_repeat('P', 32), 'arrival_time' => '10000', 'burst_time' => '1000', 'priority' => '10',
        ]]])->assertOk()->assertViewHas('processes', fn (array $processes): bool => $processes[0]->arrivalTime === 10000
            && $processes[0]->burstTime === 1000 && $processes[0]->priority === 10);
    }

    #[DataProvider('invalidCounts')]
    public function test_random_input_rejects_invalid_counts(mixed $count): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'role' => User::ROLE_ADMIN]), 'web');

        $this->post('/planificacion/aleatoria', ['count' => $count])->assertSessionHasErrors('count');
    }

    public static function invalidCounts(): array
    {
        return [[null], [0], [-1], [51], [1000000], ['2.5'], ['abc'], [true], [[]]];
    }

    public function test_random_generation_is_bounded_unique_and_reproducible(): void
    {
        $generator = app(RandomProcessGenerator::class);
        $first = $generator->generate(50, new Randomizer(new Mt19937(123)));
        $second = $generator->generate(50, new Randomizer(new Mt19937(123)));
        $this->assertEquals($first, $second);
        $this->assertCount(50, $first);
        $this->assertCount(50, array_unique(array_map(fn (SchedulingProcess $p): string => $p->id, $first)));
        foreach ($first as $process) {
            $this->assertGreaterThanOrEqual(0, $process->arrivalTime);
            $this->assertLessThanOrEqual(ProcessInput::MAX_ARRIVAL, $process->arrivalTime);
            $this->assertGreaterThanOrEqual(1, $process->burstTime);
            $this->assertLessThanOrEqual(ProcessInput::MAX_BURST, $process->burstTime);
            $this->assertGreaterThanOrEqual(ProcessInput::MIN_PRIORITY, $process->priority);
            $this->assertLessThanOrEqual(ProcessInput::MAX_PRIORITY, $process->priority);
        }
    }

    public function test_generator_itself_rejects_excessive_count(): void
    {
        $this->expectException(ValidationException::class);
        app(RandomProcessGenerator::class)->generate(51);
    }

    public function test_random_endpoint_renders_requested_count_without_database_access(): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'role' => User::ROLE_ADMIN]), 'web');

        DB::shouldReceive('connection')->never();
        $this->post('/planificacion/aleatoria', ['count' => '3'])->assertOk()
            ->assertViewIs('scheduling.index')
            ->assertViewHas('processes', fn (array $processes): bool => count($processes) === 3);
        $this->get('/planificacion')->assertViewMissing('processes');
    }

    public function test_process_names_are_escaped_in_results(): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'role' => User::ROLE_ADMIN]), 'web');

        $this->post('/planificacion/manual', ['processes' => [array_replace($this->process(), ['id' => '<script>x</script>'])]])
            ->assertOk()->assertSee('&lt;script&gt;x&lt;/script&gt;', false)->assertDontSee('<script>x</script>', false);
    }

    public function test_forms_expose_post_actions_csrf_and_limits(): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'role' => User::ROLE_OBSERVER]), 'web');

        $this->get('/planificacion')->assertOk()
            ->assertSee(route('scheduling.manual'), false)
            ->assertSee(route('scheduling.random'), false)
            ->assertSee('name="_token"', false)
            ->assertSee('name="processes[0][arrival_time]"', false)
            ->assertSee('max="50"', false);
    }

    public function test_float_types_are_rejected_even_when_mathematically_integral(): void
    {
        $input = app(ProcessInput::class);
        try {
            $input->validate(['processes' => [array_replace($this->process(), ['burst_time' => 1.0])]]);
            $this->fail('Float input must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('processes.0.burst_time', $exception->errors());
        }
        $this->expectException(ValidationException::class);
        $input->validateCount(['count' => 1.0]);
    }

    public function test_empty_batch_errors_leave_an_editable_row(): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'role' => User::ROLE_ADMIN]), 'web');

        $this->from('/planificacion')->post('/planificacion/manual', ['processes' => []])
            ->assertRedirect('/planificacion');
        $this->get('/planificacion')->assertOk()
            ->assertSee('name="processes[0][id]"', false);
    }

    public function test_both_post_endpoints_require_csrf(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        $this->post('/planificacion/manual', ['processes' => [$this->process()]])->assertStatus(419);
        $this->post('/planificacion/aleatoria', ['count' => 1])->assertStatus(419);
    }

    private function process(): array
    {
        return ['id' => 'P1', 'arrival_time' => 0, 'burst_time' => 5, 'priority' => 1];
    }
}
