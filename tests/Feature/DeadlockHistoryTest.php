<?php

namespace Tests\Feature;

use App\Models\DeadlockSimulation;
use App\Services\Deadlock\BankerSafetyAnalyzer;
use App\Services\Deadlock\DeadlockSimulationStore;
use App\Services\Deadlock\NeedCalculator;
use App\Services\Deadlock\ScenarioInput;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeadlockHistoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertTrue($this->app->environment('testing'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $connection = $this->app['db']->connection('sqlite');
        $this->assertSame('sqlite', $connection->getDriverName());
        $this->assertSame(':memory:', $connection->getDatabaseName());
        // Only the new M4 migration, in isolated memory; no refresh, reset or cleanup commands.
        $this->artisan('migrate', [
            '--database' => 'sqlite',
            '--path' => 'database/migrations/2026_10_16_000000_create_deadlock_simulations_table.php',
            '--force' => true,
        ])->assertExitCode(0);
    }

    private function data(): array
    {
        return ['n' => '3', 'm' => '2', 'allocation' => '[[1,2],[2,0],[0,1]]', 'max' => '[[3,2],[2,1],[1,2]]', 'available' => '[0,1]'];
    }

    public function test_schema_and_model_are_dedicated_to_sqlite_and_guard_mass_assignment(): void
    {
        $this->assertSame(['id', 'n', 'm', 'allocation', 'max', 'available', 'created_at', 'updated_at'], Schema::connection('sqlite')->getColumnListing('deadlock_simulations'));
        $this->assertSame('sqlite', (new DeadlockSimulation)->getConnectionName());
        $this->assertTrue((new DeadlockSimulation)->isGuarded('n'));
        $this->assertFalse(Schema::connection('sqlite')->hasTable('scheduling_runs'));
        $this->assertFalse(Schema::connection('sqlite')->hasTable('managed_processes'));
    }

    public function test_save_normalizes_only_validated_scenario_and_redirects_with_success_message(): void
    {
        $data = $this->data() + ['id' => 80, 'need' => [[999]], 'is_safe' => false, 'request' => [1, 0], 'strategy' => 'victim', 'password' => 'discard-me'];
        $this->post('/interbloqueos/historial', $data)->assertRedirect('/interbloqueos/historial/1')
            ->assertSessionHas('deadlockSaved', 'Simulación educativa guardada correctamente.');
        $saved = DeadlockSimulation::query()->sole();
        $this->assertSame(1, $saved->id);
        $this->assertSame(3, $saved->n);
        $this->assertSame(2, $saved->m);
        $this->assertSame([[1, 2], [2, 0], [0, 1]], $saved->allocation);
        $this->assertSame([[3, 2], [2, 1], [1, 2]], $saved->max);
        $this->assertSame([0, 1], $saved->available);
        $this->assertStringNotContainsString('discard-me', $saved->toJson());
        $this->get('/interbloqueos/historial/1')->assertOk()->assertSeeText('Simulación educativa guardada correctamente.');
    }

    public function test_detail_revalidates_and_recalculates_safety_need_and_steps(): void
    {
        $input = new ScenarioInput;
        $store = new DeadlockSimulationStore($input);
        $saved = $store->save($this->data());
        $before = $saved->fresh()->getRawOriginal();
        $scenario = $input->validate($this->data());
        $expected = (new BankerSafetyAnalyzer(new NeedCalculator))->analyze($scenario);
        $analyzer = $this->mock(BankerSafetyAnalyzer::class);
        $analyzer->shouldReceive('analyze')->once()->withArgs(fn ($value) => $value == $scenario)->andReturn($expected);
        $response = $this->get('/interbloqueos/historial/'.$saved->id)->assertOk()
            ->assertSeeText('Simulación guardada #'.$saved->id)->assertSeeText('Resultados recalculados')
            ->assertSeeText('P2 → P1 → P3')->assertSeeText('Pasos reales del análisis de seguridad')
            ->assertSeeText('Grafo de asignación de recursos')->assertSeeText('Detección de ciclos dirigidos')
            ->assertSeeText('Evaluar solicitud de recursos')->assertSeeText('Recuperación conceptual')
            ->assertViewHas('need', [[2, 0], [0, 1], [1, 1]])
            ->assertViewHas('safety', fn ($result) => $result == $expected)
            ->assertViewMissing('requestResult');
        $this->assertEquals($scenario, $response->viewData('scenario'));
        $this->assertSame($before, $saved->fresh()->getRawOriginal());
    }

    public function test_empty_history_and_pagination_use_deterministic_descending_id_order(): void
    {
        $this->get('/interbloqueos/historial')->assertOk()->assertSeeText('No hay simulaciones guardadas.');
        $store = $this->app->make(DeadlockSimulationStore::class);
        for ($i = 0; $i < 21; $i++) {
            $store->save($this->data());
        }
        $this->get('/interbloqueos/historial')->assertOk()
            ->assertViewHas('simulations', fn ($rows) => $rows->pluck('id')->all() === range(21, 2) && $rows->total() === 21);
        $this->get('/interbloqueos/historial?page=2')->assertOk()
            ->assertViewHas('simulations', fn ($rows) => $rows->pluck('id')->all() === [1]);
    }

    public function test_reads_new_saves_and_new_simulations_never_modify_previous_records(): void
    {
        $this->post('/interbloqueos/historial', $this->data())->assertRedirect();
        $first = DeadlockSimulation::query()->sole();
        $before = $first->getRawOriginal();
        $data = $this->data();
        $data['available'] = [2, 3];
        $data['id'] = $first->id;
        $this->post('/interbloqueos/historial', $data)->assertRedirect('/interbloqueos/historial/2');
        $this->get('/interbloqueos/historial')->assertOk();
        $this->get('/interbloqueos/historial/1?available=[9,9]')->assertOk();
        $this->put('/interbloqueos/historial/1', $data)->assertStatus(405);
        $this->delete('/interbloqueos/historial/1')->assertStatus(405);
        $this->assertSame($before, $first->fresh()->getRawOriginal());
        $this->assertDatabaseCount('deadlock_simulations', 2, 'sqlite');
        $this->post('/interbloqueos', $this->data())->assertOk();
        $data = $this->data() + ['process' => 2, 'request' => [0, 0]];
        $this->post('/interbloqueos/solicitudes', $data)->assertOk();
        $data += ['strategy' => 'terminate', 'recovery_process' => 1];
        $this->post('/interbloqueos/recuperacion', $data)->assertOk();
        $this->assertDatabaseCount('deadlock_simulations', 2, 'sqlite');
        $this->assertSame($before, $first->fresh()->getRawOriginal());
    }

    public static function invalidScenarios(): iterable
    {
        yield 'missing dimension' => ['n', null, 'n'];
        yield 'dimension above limit' => ['n', 21, 'n'];
        yield 'zero resources' => ['m', 0, 'm'];
        yield 'noncanonical dimension' => ['n', '03', 'n'];
        yield 'wrong rows' => ['allocation', [[1, 2]], 'allocation'];
        yield 'wrong columns' => ['available', [1], 'available'];
        yield 'exceeds max' => ['allocation', [[4, 2], [2, 0], [0, 1]], 'allocation.0.0'];
        yield 'negative' => ['available', [-1, 0], 'available.0'];
        yield 'over value limit' => ['available', [1000001, 0], 'available.0'];
        yield 'boolean' => ['available', [true, 0], 'available.0'];
        yield 'object instead of list' => ['available', '{"0":1,"1":2}', 'available'];
        yield 'script input' => ['max', '<script>alert(1)</script>', 'max'];
    }

    #[DataProvider('invalidScenarios')]
    public function test_invalid_scenarios_never_write(string $field, mixed $value, string $error): void
    {
        $data = $this->data();
        $data[$field] = $value;
        $this->postJson('/interbloqueos/historial', $data)->assertUnprocessable()->assertJsonValidationErrors($error);
        $this->assertDatabaseCount('deadlock_simulations', 0, 'sqlite');
    }

    public function test_service_also_validates_before_writing(): void
    {
        try {
            $this->app->make(DeadlockSimulationStore::class)->save(['n' => 0]);
            $this->fail('Invalid scenarios must be rejected.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('deadlock_simulations', 0, 'sqlite');
        }
    }

    public function test_body_limits_and_untrimmed_values_are_enforced(): void
    {
        $data = $this->data();
        $data['extra'] = str_repeat('x', ScenarioInput::MAX_PAYLOAD_BYTES);
        $this->post('/interbloqueos/historial', $data)->assertStatus(413)->assertSessionMissing('_old_input');
        $data = $this->data();
        $data['n'] = ' 3 ';
        $this->call('POST', '/interbloqueos/historial', [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'HTTP_ACCEPT' => 'application/json'], http_build_query($data))
            ->assertUnprocessable()->assertJsonValidationErrors('n');
        $this->assertDatabaseCount('deadlock_simulations', 0, 'sqlite');
    }

    public static function invalidIds(): iterable
    {
        foreach (['0', '-1', '+1', '01', '1.0', 'abc', '1e0', '9223372036854775808', str_repeat('9', 80), '1 OR 1=1'] as $id) {
            yield $id => [$id];
        }
    }

    #[DataProvider('invalidIds')]
    public function test_invalid_identifiers_are_rejected_before_querying(string $id): void
    {
        $connection = (new DeadlockSimulation)->getConnection();
        $connection->enableQueryLog();
        $this->get('/interbloqueos/historial/'.rawurlencode($id))->assertNotFound()
            ->assertSeeText('La simulación solicitada no existe.');
        $this->assertSame([], $connection->getQueryLog());
    }

    public function test_nonexistent_identifier_has_a_clear_404(): void
    {
        $this->get('/interbloqueos/historial/999')->assertNotFound()->assertSeeText('La simulación solicitada no existe.');
    }

    public function test_corrupt_stored_json_is_revalidated_instead_of_being_analyzed_or_updated(): void
    {
        $saved = $this->app->make(DeadlockSimulationStore::class)->save($this->data());
        // Deliberately corrupt only this isolated test record using a parameterized Eloquent update.
        DeadlockSimulation::query()->whereKey($saved->id)->update(['available' => '{"0":0,"1":1}']);
        $before = $saved->fresh()->getRawOriginal();
        $this->mock(BankerSafetyAnalyzer::class)->shouldNotReceive('analyze');
        $this->get('/interbloqueos/historial/'.$saved->id)->assertUnprocessable()
            ->assertSeeText('contiene un escenario inválido');
        $this->assertSame($before, $saved->fresh()->getRawOriginal());
    }

    public function test_save_form_is_visible_only_with_valid_scenario_and_uses_csrf(): void
    {
        $this->get('/interbloqueos')->assertOk()->assertDontSeeText('Guardar una nueva simulación');
        $this->post('/interbloqueos', $this->data())->assertOk()
            ->assertSee('action="'.route('deadlocks.history.store').'"', false)
            ->assertSee('name="_token"', false)->assertSeeText('Guardar una nueva simulación');
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->post('/interbloqueos/historial', $this->data())->assertStatus(419);
        $this->assertDatabaseCount('deadlock_simulations', 0, 'sqlite');
    }

    public function test_database_unavailability_returns_clear_messages_without_query_details(): void
    {
        $originalResolver = DeadlockSimulation::getConnectionResolver();
        $resolver = \Mockery::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('connection')->andThrow(new QueryException('sqlite', 'private-query', [], new \RuntimeException('private-database-error')));
        DeadlockSimulation::setConnectionResolver($resolver);
        try {
            $this->get('/interbloqueos/historial')->assertStatus(503)
                ->assertSeeText('no está disponible')->assertDontSee('private-query', false);
            $this->get('/interbloqueos/historial/1')->assertStatus(503)
                ->assertSeeText('no está disponible')->assertDontSee('private-database-error', false);
            $this->post('/interbloqueos/historial', $this->data())->assertStatus(503)
                ->assertSeeText('No se pudo guardar la simulación')->assertDontSee('private-query', false);
        } finally {
            DeadlockSimulation::setConnectionResolver($originalResolver);
        }
    }

    public function test_history_messages_escape_displayed_content(): void
    {
        $this->view('deadlocks.history-message', ['message' => '<script>alert(1)</script>'])
            ->assertSee('&lt;script&gt;', false)->assertDontSee('<script>', false);
    }

    public function test_malformed_stored_timestamp_does_not_break_history_or_render_html(): void
    {
        $saved = $this->app->make(DeadlockSimulationStore::class)->save($this->data());
        DeadlockSimulation::query()->whereKey($saved->id)->update(['created_at' => '<script>private-invalid-date</script>']);
        $this->get('/interbloqueos/historial')->assertOk()
            ->assertSee('&lt;script&gt;', false)->assertDontSee('<script>', false)
            ->assertDontSee('Stack trace', false);
    }

    public static function corruptScenarios(): iterable
    {
        yield 'invalid JSON syntax' => ['allocation', '['];
        yield 'object matrix' => ['max', '{"0":[3,2],"1":[2,1],"2":[1,2]}'];
        yield 'nested vector' => ['available', '[[0],1]'];
        yield 'boolean vector' => ['available', '[false,1]'];
        yield 'negative vector' => ['available', '[-1,1]'];
        yield 'decimal vector' => ['available', '[0.0,1]'];
        yield 'null vector' => ['available', 'null'];
        yield 'out of bounds dimensions' => ['n', 21];
    }

    #[DataProvider('corruptScenarios')]
    public function test_corrupt_stored_scenarios_never_reach_analysis(string $field, mixed $value): void
    {
        $saved = $this->app->make(DeadlockSimulationStore::class)->save($this->data());
        DeadlockSimulation::query()->whereKey($saved->id)->update([$field => $value]);
        $before = $saved->fresh()->getRawOriginal();
        $this->mock(BankerSafetyAnalyzer::class)->shouldNotReceive('analyze');
        $this->get('/interbloqueos/historial/'.$saved->id)->assertUnprocessable()
            ->assertSeeText('contiene un escenario inválido')->assertDontSee('SQLSTATE', false);
        $this->assertSame($before, $saved->fresh()->getRawOriginal());
    }

    public function test_missing_sqlite_file_does_not_expose_its_path_even_in_debug_mode(): void
    {
        $privatePath = '/tmp/sysmonitor-m4-missing-'.bin2hex(random_bytes(12)).'.sqlite';
        $this->assertFileDoesNotExist($privatePath);
        config(['app.debug' => true, 'database.connections.sqlite.database' => $privatePath]);
        DB::purge('sqlite');
        try {
            $this->get('/interbloqueos/historial')->assertStatus(503)->assertDontSee($privatePath, false);
            $this->get('/interbloqueos/historial/1')->assertStatus(503)->assertDontSee($privatePath, false);
            $this->post('/interbloqueos/historial', $this->data())->assertStatus(503)->assertDontSee($privatePath, false);
        } finally {
            DB::purge('sqlite');
            config(['database.connections.sqlite.database' => ':memory:']);
        }
        $this->assertFileDoesNotExist($privatePath);
    }

    public function test_maximum_dimensions_and_values_round_trip_without_losing_order(): void
    {
        $matrix = array_fill(0, 20, array_fill(0, 20, ScenarioInput::MAX_VALUE));
        $data = ['n' => 20, 'm' => 20, 'allocation' => $matrix, 'max' => $matrix, 'available' => array_fill(0, 20, 0)];
        $this->postJson('/interbloqueos/historial', $data)->assertRedirect();
        $this->get('/interbloqueos/historial/1')->assertOk()
            ->assertViewHas('scenario', fn ($scenario) => $scenario->allocation === $matrix && $scenario->available === array_fill(0, 20, 0))
            ->assertViewHas('safety', fn ($result) => $result->safeSequence === range(0, 19));
    }
}
