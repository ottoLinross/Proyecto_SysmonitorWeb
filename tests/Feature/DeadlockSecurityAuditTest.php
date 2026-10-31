<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Deadlock\ScenarioInput;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeadlockSecurityAuditTest extends TestCase
{
    private function data(): array
    {
        return ['n' => 1, 'm' => 1, 'allocation' => [[1]], 'max' => [[2]], 'available' => [1], 'process' => 1, 'request' => [1], 'strategy' => 'terminate', 'recovery_process' => 1];
    }

    public static function postRoutes(): iterable
    {
        foreach (['/interbloqueos', '/interbloqueos/solicitudes', '/interbloqueos/recuperacion', '/interbloqueos/historial'] as $route) {
            yield $route => [$route];
        }
    }

    #[DataProvider('postRoutes')]
    public function test_unsupported_content_types_cannot_fall_back_to_query_parameters(string $route): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'role' => User::ROLE_ADMIN]), 'web');

        $data = $this->data();
        $data['n'] = ' 1 ';
        $this->call('POST', $route.'?'.http_build_query($data), [], [], [], ['CONTENT_TYPE' => 'text/plain', 'HTTP_ACCEPT' => 'application/json'], 'ignored')
            ->assertStatus(415);
    }

    #[DataProvider('postRoutes')]
    public function test_empty_form_body_does_not_take_scenario_from_query_parameters(string $route): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'role' => User::ROLE_ADMIN]), 'web');

        $this->postJson($route.'?'.http_build_query($this->data()), [])->assertUnprocessable();
        $this->call('POST', $route.'?'.http_build_query($this->data()), [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'HTTP_ACCEPT' => 'application/json'], '')
            ->assertUnprocessable()->assertJsonValidationErrors('n');
    }

    #[DataProvider('postRoutes')]
    public function test_every_post_route_rejects_missing_and_invalid_csrf_before_processing(string $route): void
    {
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->post($route, $this->data())->assertStatus(419);
        $this->withSession(['_token' => 'expected-token'])->post($route, $this->data() + ['_token' => 'wrong-token'])->assertStatus(419);
    }

    #[DataProvider('postRoutes')]
    public function test_excessive_body_and_declared_length_are_rejected_without_flashing_input(string $route): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'role' => User::ROLE_ADMIN]), 'web');

        $this->call('POST', $route, [], [], [], ['CONTENT_TYPE' => 'application/json'], str_repeat(' ', ScenarioInput::MAX_PAYLOAD_BYTES + 1))
            ->assertStatus(413)->assertSessionMissing('_old_input');
        $this->call('POST', $route, [], [], [], ['CONTENT_TYPE' => 'application/json', 'CONTENT_LENGTH' => ScenarioInput::MAX_PAYLOAD_BYTES + 1], json_encode($this->data()))
            ->assertStatus(413)->assertSessionMissing('_old_input');
    }

    public function test_content_type_case_is_insensitive_without_weakening_numeric_validation(): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'role' => User::ROLE_ADMIN]), 'web');

        $data = $this->data();
        $this->call('POST', '/interbloqueos', [], [], [], ['CONTENT_TYPE' => 'Application/X-Www-Form-Urlencoded; charset=UTF-8'], http_build_query($data))->assertOk();
        $data['n'] = ' 1 ';
        $this->call('POST', '/interbloqueos', [], [], [], ['CONTENT_TYPE' => 'Application/X-Www-Form-Urlencoded', 'HTTP_ACCEPT' => 'application/json'], http_build_query($data))
            ->assertUnprocessable()->assertJsonValidationErrors('n');
    }

    public function test_urlencoded_parser_limits_return_validation_errors_without_internal_details(): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'role' => User::ROLE_ADMIN]), 'web');

        $forms = [
            http_build_query($this->data()).'&'.implode('&', array_fill(0, 1100, 'extra=1')),
            'extra'.str_repeat('[nested]', 100).'=1&'.http_build_query($this->data()),
        ];
        foreach ($forms as $body) {
            $this->call('POST', '/interbloqueos', [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'HTTP_ACCEPT' => 'application/json'], $body)
                ->assertUnprocessable()->assertJsonValidationErrors('scenario')
                ->assertDontSee('DeadlockScenarioRequest.php', false)->assertDontSee('trace', false);
        }
    }

    public function test_request_objects_nested_values_and_nonexistent_processes_are_rejected(): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'role' => User::ROLE_ADMIN]), 'web');

        foreach (['{"0":1}', '[[1]]', '[-1]', '[1.0]', '["01"]', '[true]', '[1000001]'] as $vector) {
            $data = $this->data();
            $data['request'] = $vector;
            $this->postJson('/interbloqueos/solicitudes', $data)->assertUnprocessable();
        }
        $data = $this->data();
        $data['process'] = 2;
        $this->postJson('/interbloqueos/solicitudes', $data)->assertUnprocessable()->assertJsonValidationErrors('process');
        $data = $this->data();
        $data['recovery_process'] = 2;
        $this->postJson('/interbloqueos/recuperacion', $data)->assertUnprocessable()->assertJsonValidationErrors('process');
    }

    public function test_html_request_errors_escape_malformed_content(): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'role' => User::ROLE_ADMIN]), 'web');

        $data = $this->data();
        $data['request'] = '"></textarea><script>alert(1)</script>';
        $this->post('/interbloqueos/solicitudes', $data)->assertUnprocessable()
            ->assertSee('&lt;script&gt;', false)->assertDontSee('<script>', false);
    }
}
