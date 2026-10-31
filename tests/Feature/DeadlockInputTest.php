<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Deadlock\ScenarioInput;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeadlockInputTest extends TestCase
{
    private function form(): array
    {
        return ['n' => '2', 'm' => '2', 'allocation' => '[[1,0],[0,2]]', 'max' => '[[3,1],[2,4]]', 'available' => '[5,3]'];
    }

    public function test_form_validates_and_displays_the_scenario_without_database_access(): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'role' => User::ROLE_ADMIN]), 'web');

        DB::shouldReceive('connection')->never();
        $this->post(route('deadlocks.validate'), $this->form())
            ->assertOk()->assertViewIs('deadlocks.index')->assertSeeText('Escenario válido')
            ->assertViewHas('scenario', fn ($value) => $value->allocation === [[1, 0], [0, 2]] && $value->available === [5, 3]);
    }

    public function test_structured_json_input_is_supported(): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'role' => User::ROLE_ADMIN]), 'web');

        $this->postJson('/interbloqueos', ['n' => 1, 'm' => 2, 'allocation' => [[0, 1]], 'max' => [[2, 3]], 'available' => [4, 0]])
            ->assertOk()->assertViewHas('scenario', fn ($value) => $value->max === [[2, 3]]);
    }

    public function test_errors_are_displayed_and_form_values_are_preserved(): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'role' => User::ROLE_ADMIN]), 'web');

        $data = $this->form();
        $data['allocation'] = '[[9,0],[0,2]]';
        $this->from('/interbloqueos')->post('/interbloqueos', $data)
            ->assertRedirect('/interbloqueos')->assertSessionHasErrors('allocation.0.0')->assertSessionHasInput('allocation', $data['allocation']);
        $this->get('/interbloqueos')->assertOk()->assertSeeText('Revisa el escenario')->assertSeeText('Allocation no puede superar Max');
    }

    public function test_ambiguous_json_numbers_are_not_silently_trimmed(): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'role' => User::ROLE_ADMIN]), 'web');

        $data = $this->form();
        $data['n'] = ' 2 ';
        $this->postJson('/interbloqueos', $data)->assertUnprocessable()->assertJsonValidationErrors('n');
    }

    public function test_original_urlencoded_values_are_validated(): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'role' => User::ROLE_ADMIN]), 'web');

        $data = $this->form();
        $data['m'] = ' 2 ';
        $this->call('POST', '/interbloqueos', [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'HTTP_ACCEPT' => 'application/json'], http_build_query($data))
            ->assertUnprocessable()->assertJsonValidationErrors('m');
    }

    public function test_excessive_raw_body_is_rejected_without_flashing_input(): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'role' => User::ROLE_ADMIN]), 'web');

        $this->call('POST', '/interbloqueos', [], [], [], ['CONTENT_TYPE' => 'application/json'], str_repeat(' ', ScenarioInput::MAX_PAYLOAD_BYTES + 1))
            ->assertStatus(413)->assertSessionMissing('_old_input');
    }

    public function test_excessive_parsed_payload_is_rejected(): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'role' => User::ROLE_ADMIN]), 'web');

        $this->post('/interbloqueos', ['extra' => str_repeat('x', ScenarioInput::MAX_PAYLOAD_BYTES + 1)])
            ->assertStatus(413)->assertSessionMissing('_old_input');
    }

    public function test_declared_excessive_body_is_rejected(): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'role' => User::ROLE_ADMIN]), 'web');

        $this->withHeader('Content-Length', (string) (ScenarioInput::MAX_PAYLOAD_BYTES + 1))->post('/interbloqueos', $this->form())->assertStatus(413);
    }

    public function test_malformed_json_returns_validation_error(): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'role' => User::ROLE_ADMIN]), 'web');

        $this->call('POST', '/interbloqueos', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{broken')
            ->assertUnprocessable()->assertJsonValidationErrors('scenario');
    }

    public function test_invalid_input_is_escaped_when_redisplayed(): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'role' => User::ROLE_ADMIN]), 'web');

        $data = $this->form();
        $data['allocation'] = '<script>alert(1)</script>';
        $this->from('/interbloqueos')->post('/interbloqueos', $data)->assertSessionHasErrors('allocation');
        $this->get('/interbloqueos')->assertOk()->assertDontSee('<script>', false)->assertSee('&lt;script&gt;', false);
    }

    public function test_json_objects_with_numeric_keys_are_not_matrices(): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'role' => User::ROLE_ADMIN]), 'web');

        $this->call('POST', '/interbloqueos', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{"n":1,"m":1,"allocation":{"0":[0]},"max":[[1]],"available":[0]}')
            ->assertUnprocessable()->assertJsonValidationErrors('allocation');
    }

    public function test_multipart_input_is_not_supported(): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'role' => User::ROLE_ADMIN]), 'web');

        $this->withHeader('Content-Type', 'multipart/form-data; boundary=test')->post('/interbloqueos', $this->form())->assertStatus(415);
    }

    public function test_raw_body_at_the_size_limit_is_accepted(): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'role' => User::ROLE_ADMIN]), 'web');

        $body = json_encode($this->form());
        $body = str_pad($body, ScenarioInput::MAX_PAYLOAD_BYTES, ' ');
        $this->call('POST', '/interbloqueos', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)
            ->assertOk()->assertSeeText('Escenario válido');
    }
}
