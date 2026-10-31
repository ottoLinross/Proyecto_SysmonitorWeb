<?php

namespace Tests\Feature;

use App\Models\SchedulingRun;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesSchedulingStorage;
use Tests\TestCase;

class SchedulingAuditTest extends TestCase
{
    use CreatesSchedulingStorage;

    #[DataProvider('batchEndpoints')]
    public function test_oversized_batches_are_rejected_before_expanding_row_validation(string $endpoint): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'role' => User::ROLE_ADMIN]), 'web');

        $response = $this->postJson($endpoint, [
            'algorithm' => 'fcfs', 'quantum' => 1,
            'processes' => array_fill(0, 500, ['id' => 'duplicate', 'burst_time' => -1]),
        ])->assertUnprocessable();

        $this->assertSame(['processes'], array_keys($response->json('errors')));
        $this->assertDatabaseCount('scheduling_runs', 0);
    }

    public static function batchEndpoints(): array
    {
        return [['/planificacion/manual'], ['/planificacion/simular'], ['/planificacion/comparar']];
    }

    #[DataProvider('extremeInputs')]
    public function test_extreme_and_noninteger_input_never_reaches_storage(array $changes, string $field): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'role' => User::ROLE_ADMIN]), 'web');

        $payload = ['algorithm' => 'round_robin', 'quantum' => 1, 'processes' => [[
            'id' => 'P1', 'arrival_time' => 0, 'burst_time' => 1, 'priority' => 1,
        ]]];
        if ($field === 'quantum') {
            $payload['quantum'] = $changes['quantum'];
        } else {
            $payload['processes'][0] = array_replace($payload['processes'][0], $changes);
        }

        $this->postJson('/planificacion/simular', $payload, [], JSON_PRESERVE_ZERO_FRACTION)
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('scheduling_runs', 0);
    }

    public static function extremeInputs(): array
    {
        return [
            [['arrival_time' => str_repeat('9', 100)], 'processes.0.arrival_time'],
            [['burst_time' => PHP_INT_MAX], 'processes.0.burst_time'],
            [['priority' => PHP_INT_MAX], 'processes.0.priority'],
            [['burst_time' => 1.0], 'processes.0.burst_time'],
            [['quantum' => str_repeat('9', 100)], 'quantum'],
            [['quantum' => 1.0], 'quantum'],
        ];
    }

    public function test_a_saved_comparison_is_not_changed_by_a_later_simulation(): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'role' => User::ROLE_ADMIN]), 'web');

        $payload = ['quantum' => 1, 'processes' => [[
            'id' => 'P1', 'arrival_time' => 2, 'burst_time' => 3, 'priority' => 1,
        ]]];
        $this->post('/planificacion/comparar', $payload)->assertOk();
        $saved = SchedulingRun::query()->sole();
        $before = $saved->getAttributes();
        $this->post('/planificacion/simular', array_replace($payload, ['algorithm' => 'srtf', 'quantum' => 10]))->assertOk();
        $this->assertSame($before, $saved->fresh()->getAttributes());
    }
}
