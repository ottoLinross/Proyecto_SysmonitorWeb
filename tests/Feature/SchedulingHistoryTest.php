<?php

namespace Tests\Feature;

use App\Models\SchedulingRun;
use App\Models\User;
use App\Services\Scheduling\SchedulingComparison;
use App\Services\Scheduling\SchedulingSimulation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\CreatesSchedulingStorage;
use Tests\TestCase;

class SchedulingHistoryTest extends TestCase
{
    use CreatesSchedulingStorage;

    public function test_migration_creates_the_m3_table_and_json_casts(): void
    {
        $this->assertTrue(Schema::connection('sqlite')->hasColumns('scheduling_runs', [
            'id', 'schema_version', 'mode', 'algorithm', 'quantum', 'input_processes', 'results', 'created_at', 'updated_at',
        ]));
        $this->assertSame('sqlite', (new SchedulingRun)->getConnection()->getDriverName());
        $this->assertSame('array', (new SchedulingRun)->getCasts()['input_processes']);
        $this->assertSame('array', (new SchedulingRun)->getCasts()['results']);
    }

    #[DataProvider('algorithms')]
    public function test_each_algorithm_is_saved_with_unchanged_results_and_recovers_from_json(string $algorithm): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'role' => User::ROLE_ADMIN]), 'web');

        $response = $this->post('/planificacion/simular', $this->payload($algorithm))->assertOk();
        $run = SchedulingRun::query()->sole();
        $result = $response->viewData('simulation');

        $this->assertSame('single', $run->mode);
        $this->assertSame($algorithm, $run->algorithm);
        $this->assertSame(1, $run->schema_version);
        $this->assertSame($algorithm === 'round_robin' ? 2 : null, $run->quantum);
        $this->assertSame($this->payload($algorithm)['processes'], $run->input_processes);
        $this->assertSame([$result], $run->results);
        $this->assertNotNull($run->created_at);
        $this->assertSame($run->id, $response->viewData('savedRun')->id);
        $raw = DB::connection('sqlite')->table('scheduling_runs')->first();
        $this->assertSame($run->input_processes, json_decode($raw->input_processes, true, 512, JSON_THROW_ON_ERROR));
        $this->assertSame([$result], json_decode($raw->results, true, 512, JSON_THROW_ON_ERROR));

        // A later GET must read the snapshot, without invoking a scheduler again.
        $this->mock(SchedulingSimulation::class)->shouldNotReceive('run');
        $detail = $this->get(route('scheduling.history.show', $run->id))->assertOk()
            ->assertSeeText('Simulación guardada #'.$run->id)->assertSeeText('Diagrama de Gantt')
            ->assertSeeText('CPU ociosa (idle)');
        $this->assertSame($result, $detail->viewData('simulation'));
        $this->assertDatabaseCount('scheduling_runs', 1);
    }

    public static function algorithms(): array
    {
        return array_map(fn (string $algorithm): array => [$algorithm], array_keys(SchedulingSimulation::ALGORITHMS));
    }

    public function test_comparison_persists_all_five_complete_results_and_detail_gantts_without_recalculation(): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'role' => User::ROLE_ADMIN]), 'web');

        $response = $this->post('/planificacion/comparar', $this->payload())->assertOk();
        $run = SchedulingRun::query()->sole();
        $this->assertSame('comparison', $run->mode);
        $this->assertNull($run->algorithm);
        $this->assertSame(2, $run->quantum);
        $this->assertSame($response->viewData('comparison')['results'], $run->results);
        $this->assertSame(['fcfs', 'sjf', 'srtf', 'round_robin', 'priority'], array_column($run->results, 'algorithm'));
        foreach ($run->results as $result) {
            $this->assertNotEmpty($result['segments']);
            $this->assertCount(2, $result['metrics']['processes']);
        }

        $this->mock(SchedulingComparison::class)->shouldNotReceive('compare');
        $this->mock(SchedulingSimulation::class)->shouldNotReceive('run');
        $detail = $this->get(route('scheduling.history.show', $run->id))->assertOk()
            ->assertSeeText('Comparación de algoritmos');
        $this->assertSame($run->results, $detail->viewData('comparison')['results']);
        foreach (array_keys(SchedulingSimulation::ALGORITHMS) as $algorithm) {
            $detail->assertSee('id="saved-'.$algorithm.'-gantt-segments"', false);
        }
        $document = new \DOMDocument;
        @$document->loadHTML($detail->getContent());
        $xpath = new \DOMXPath($document);
        $ids = [];
        foreach ($xpath->query('//*[@id]') as $element) {
            $ids[] = $element->getAttribute('id');
        }
        $this->assertSame($ids, array_values(array_unique($ids)));
        $this->assertDatabaseCount('scheduling_runs', 1);
    }

    public function test_history_is_empty_then_paginated_with_stable_newest_first_order(): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'role' => User::ROLE_ADMIN]), 'web');

        $this->get('/planificacion/historial')->assertOk()->assertSeeText('No hay simulaciones guardadas.');
        $this->freezeTime();
        $saved = [];
        for ($i = 0; $i < 21; $i++) {
            $response = $this->post('/planificacion/simular', $this->payload())->assertOk();
            $saved[] = $response->viewData('savedRun')->id;
        }
        $first = $this->get('/planificacion/historial')->assertOk()->assertSeeText('Siguiente');
        $this->assertSame(array_slice(array_reverse($saved), 0, 20), $first->viewData('runs')->pluck('id')->all());
        $first->assertSee(route('scheduling.history.show', $saved[20]), false);
        $last = $this->get('/planificacion/historial?page=2')->assertOk()->assertSeeText('Anterior');
        $this->assertSame([$saved[0]], $last->viewData('runs')->pluck('id')->all());
    }

    public function test_snapshot_survives_reopening_an_isolated_sqlite_file(): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'role' => User::ROLE_ADMIN]), 'web');

        $path = tempnam(sys_get_temp_dir(), 'scheduling-history-');
        $this->assertNotFalse($path);
        try {
            DB::purge('sqlite');
            config(['database.connections.sqlite.database' => $path]);
            $this->artisan('migrate', [
                '--database' => 'sqlite', '--path' => 'database/migrations/2026_10_15_000000_create_scheduling_runs_table.php',
                '--force' => true,
            ])->assertExitCode(0);
            $response = $this->post('/planificacion/simular', $this->payload('round_robin'))->assertOk();
            $id = $response->viewData('savedRun')->id;
            $expected = $response->viewData('simulation');

            DB::purge('sqlite');
            $this->assertSame($expected, SchedulingRun::query()->findOrFail($id)->results[0]);
            $this->get(route('scheduling.history.show', $id))->assertOk()->assertSeeText('Quantum: 2');
        } finally {
            DB::purge('sqlite');
            config(['database.connections.sqlite.database' => ':memory:']);
            unlink($path);
        }
    }

    public function test_nonexistent_and_nonnumeric_identifiers_return_404_and_detail_cannot_be_updated(): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'role' => User::ROLE_ADMIN]), 'web');

        $this->get('/planificacion/historial/999')->assertNotFound();
        $this->get('/planificacion/historial/abc')->assertNotFound();
        $this->post('/planificacion/simular', $this->payload())->assertOk();
        $run = SchedulingRun::query()->sole();
        $before = $run->getAttributes();
        $this->get(route('scheduling.history.show', ['id' => $run->id, 'quantum' => 99, 'algorithm' => 'srtf']))->assertOk();
        $this->put(route('scheduling.history.show', $run->id), ['quantum' => 99])->assertStatus(405);
        $this->assertSame($before, $run->fresh()->getAttributes());
    }

    public function test_untrusted_results_are_ignored_and_invalid_input_is_not_saved(): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'role' => User::ROLE_ADMIN]), 'web');

        $payload = $this->payload();
        $payload['results'] = [['metrics' => ['average_waiting_time' => -999]]];
        $payload['mode'] = 'forged';
        $this->post('/planificacion/simular', $payload)->assertOk();
        $run = SchedulingRun::query()->sole();
        $this->assertSame('single', $run->mode);
        $this->assertGreaterThanOrEqual(0, $run->results[0]['metrics']['average_waiting_time']);
        $payload['processes'][0]['burst_time'] = 0;
        $this->post('/planificacion/simular', $payload)->assertSessionHasErrors();
        $this->assertDatabaseCount('scheduling_runs', 1);
    }

    public function test_failed_save_rolls_back_and_preserves_results_without_claiming_success(): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'role' => User::ROLE_ADMIN]), 'web');

        SchedulingRun::created(function (): void {
            throw new QueryException('sqlite', 'insert scheduling_runs', [], new RuntimeException('private-database-marker'));
        });
        try {
            $this->post('/planificacion/simular', $this->payload())->assertStatus(503)
                ->assertSeeText('No se pudo guardar la simulación.')
                ->assertSeeText('Diagrama de Gantt')->assertDontSeeText('Simulación guardada #')
                ->assertDontSee('private-database-marker', false);
            $this->assertDatabaseCount('scheduling_runs', 0);
        } finally {
            SchedulingRun::flushEventListeners();
        }
    }

    public function test_history_database_failure_is_generic_and_migration_can_be_rolled_back(): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'role' => User::ROLE_OBSERVER]), 'web');

        $this->artisan('migrate:rollback', [
            '--database' => 'sqlite', '--path' => 'database/migrations/2026_10_15_000000_create_scheduling_runs_table.php',
        ])->assertExitCode(0);
        $this->assertFalse(Schema::connection('sqlite')->hasTable('scheduling_runs'));
        $this->get('/planificacion/historial')->assertStatus(503)
            ->assertSeeText('El historial no está disponible temporalmente.')->assertDontSee('SQLSTATE', false);
        $this->get('/planificacion/historial/1')->assertStatus(503)->assertDontSee('SQLSTATE', false);
    }

    public function test_saved_names_are_escaped_and_manual_validation_does_not_save_a_simulation(): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'role' => User::ROLE_ADMIN]), 'web');

        $payload = $this->payload();
        $payload['processes'][0]['id'] = '<script>x</script>';
        $this->post('/planificacion/manual', $payload)->assertOk();
        $this->assertDatabaseCount('scheduling_runs', 0);
        $this->post('/planificacion/simular', $payload)->assertOk();
        $run = SchedulingRun::query()->sole();
        $this->get(route('scheduling.history.show', $run->id))->assertOk()
            ->assertSee('&lt;script&gt;x&lt;/script&gt;', false)->assertDontSee('<script>x</script>', false);
    }

    private function payload(string $algorithm = 'fcfs'): array
    {
        return ['algorithm' => $algorithm, 'quantum' => '2', 'processes' => [
            ['id' => 'A', 'arrival_time' => 2, 'burst_time' => 5, 'priority' => 5],
            ['id' => 'B', 'arrival_time' => 3, 'burst_time' => 1, 'priority' => 1],
        ]];
    }
}
