<?php

namespace Tests\Feature;

use App\Models\ManagedProcess;
use App\Services\System\ProcessService;
use App\Services\System\TestProcessIdentityReader;
use App\Services\System\TestProcessLauncher;
use App\Services\System\TestProcessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class TestProcessTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_fixed_test_process_is_registered_with_its_identity(): void
    {
        $this->mockLaunch('42', $this->identity());

        $process = app(TestProcessService::class)->launch();

        $this->assertIsInt($process->pid);
        $this->assertSame(42, $process->pid);
        $this->assertSame(1000, $process->owner_uid);
        $this->assertSame(12345, $process->start_time_ticks);
        $this->assertSame('sleep', $process->process_type);
        $this->assertSame('/usr/bin/sleep 300', $process->command_label);
        $this->assertSame('running', $process->status);
        $this->assertNotNull($process->launched_at);
        $this->assertNotNull($process->created_at);
        $this->assertNotNull($process->updated_at);
        $this->assertDatabaseCount('managed_processes', 1);
        $this->assertDatabaseHas('managed_processes', $this->identity());
    }

    #[DataProvider('invalidPids')]
    public function test_invalid_launcher_pids_are_not_registered(string $output): void
    {
        $this->mock(TestProcessLauncher::class, function (MockInterface $mock) use ($output) {
            $mock->shouldReceive('launch')->once()->andReturn($output);
        });
        $this->mock(TestProcessIdentityReader::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('read');
        });

        $response = $this->post('/procesos/prueba');

        $response->assertRedirect(route('processes.index'));
        $response->assertSessionHas('test_process_error', 'No se pudo crear el proceso de prueba. Inténtalo nuevamente.');
        $this->assertDatabaseCount('managed_processes', 0);
    }

    public static function invalidPids(): array
    {
        return [
            'empty' => [''], 'text' => ['not-a-pid'], 'zero' => ['0'], 'negative' => ['-1'],
            'overflow' => ['9999999999999999999999'], 'too large' => ['2147483648'],
            'multiple lines' => ["42\n43"], 'command injection' => ['42; arbitrary-command'],
        ];
    }

    #[DataProvider('invalidStoredIdentities')]
    public function test_invalid_identity_is_not_registered(array $identity): void
    {
        $this->mockLaunch('42', $identity);

        $response = $this->post('/procesos/prueba');

        $response->assertRedirect(route('processes.index'));
        $response->assertSessionHas('test_process_error');
        $this->assertDatabaseCount('managed_processes', 0);
    }

    public static function invalidStoredIdentities(): array
    {
        return [
            'missing identity' => [[]],
            'different PID' => [['pid' => 43, 'owner_uid' => 1000, 'start_time_ticks' => 12345]],
            'negative UID' => [['pid' => 42, 'owner_uid' => -1, 'start_time_ticks' => 12345]],
            'invalid UID' => [['pid' => 42, 'owner_uid' => 'invalid', 'start_time_ticks' => 12345]],
            'zero ticks' => [['pid' => 42, 'owner_uid' => 1000, 'start_time_ticks' => 0]],
            'invalid ticks' => [['pid' => 42, 'owner_uid' => 1000, 'start_time_ticks' => 'invalid']],
        ];
    }

    public function test_a_disappeared_process_is_not_registered(): void
    {
        $this->mock(TestProcessLauncher::class, function (MockInterface $mock) {
            $mock->shouldReceive('launch')->once()->andReturn('42');
        });
        $this->mock(TestProcessIdentityReader::class, function (MockInterface $mock) {
            $mock->shouldReceive('read')->with(42)->once()->andThrow(new RuntimeException('internal /proc failure'));
        });

        $response = $this->post('/procesos/prueba');

        $response->assertRedirect(route('processes.index'));
        $response->assertSessionHas('test_process_error');
        $this->assertDatabaseCount('managed_processes', 0);
    }

    public function test_launcher_errors_are_presented_as_a_generic_message(): void
    {
        $this->mock(TestProcessLauncher::class, function (MockInterface $mock) {
            $mock->shouldReceive('launch')->once()->andThrow(new RuntimeException('internal launcher detail'));
        });
        $this->mockGeneralProcesses();

        $response = $this->followingRedirects()->post('/procesos/prueba');

        $response->assertOk();
        $response->assertSeeText('No se pudo crear el proceso de prueba. Inténtalo nuevamente.');
        $response->assertDontSeeText('internal launcher detail');
        $this->assertDatabaseCount('managed_processes', 0);
    }

    public function test_database_failure_rolls_back_the_registration(): void
    {
        $this->mockLaunch('42', $this->identity());
        ManagedProcess::created(function () {
            throw new RuntimeException('internal database detail');
        });

        try {
            $response = $this->post('/procesos/prueba');
            $response->assertRedirect(route('processes.index'));
            $response->assertSessionHas('test_process_error');
            $this->assertDatabaseCount('managed_processes', 0);
        } finally {
            ManagedProcess::flushEventListeners();
        }
    }

    public function test_get_cannot_launch_a_process(): void
    {
        $this->mock(TestProcessLauncher::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('launch');
        });

        $this->get('/procesos/prueba')->assertStatus(405);
        $this->assertDatabaseCount('managed_processes', 0);
    }

    public function test_post_redirects_and_shows_success_while_ignoring_user_command_parameters(): void
    {
        $this->mockLaunch('42', $this->identity());
        $this->mockGeneralProcesses();

        $response = $this->post('/procesos/prueba', [
            'command' => 'arbitrary-command', 'duration' => 999, 'pid' => 888,
            'path' => '/tmp/other', 'arguments' => ['other'], 'owner_uid' => 9999,
        ]);

        $response->assertRedirect(route('processes.index'));
        $response->assertSessionHas('test_process_success', 'Proceso de prueba creado correctamente. PID: 42.');
        $this->assertDatabaseHas('managed_processes', [
            'pid' => 42, 'owner_uid' => 1000, 'process_type' => 'sleep', 'command_label' => '/usr/bin/sleep 300',
        ]);
        $this->get('/procesos')->assertOk()->assertSeeText('Proceso de prueba creado correctamente. PID: 42.');
    }

    public function test_the_form_contains_only_csrf_and_the_fixed_launch_button(): void
    {
        $this->mockGeneralProcesses();

        $response = $this->get('/procesos');

        $response->assertOk();
        $response->assertSeeText('Procesos de prueba');
        $response->assertSeeText('Lanzar proceso de prueba');
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame('POST', $xpath->evaluate('string(//form[@class="launch-form"]/@method)'));
        $this->assertSame(route('processes.test.store'), $xpath->evaluate('string(//form[@class="launch-form"]/@action)'));
        $this->assertSame(1, $xpath->query('//form[@class="launch-form"]//input')->length);
        $this->assertSame('_token', $xpath->evaluate('string(//form[@class="launch-form"]//input/@name)'));
        $this->assertSame(0, $xpath->query('//form[@class="launch-form"]//textarea | //form[@class="launch-form"]//select')->length);
    }

    public function test_post_has_normal_csrf_protection(): void
    {
        $this->mock(TestProcessLauncher::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('launch');
        });
        // La protección CSRF se omite normalmente en testing; activar su ruta de producción.
        $this->app->detectEnvironment(fn () => 'local');

        $this->post('/procesos/prueba')->assertStatus(419);
        $this->assertDatabaseCount('managed_processes', 0);
    }

    public function test_registered_processes_and_messages_are_displayed_escaped(): void
    {
        $this->mockGeneralProcesses();
        $process = ManagedProcess::create([
            ...$this->identity(),
            'process_type' => '<b>sleep</b>',
            'command_label' => '<script>alert("stored")</script>',
            'status' => '<i>running</i>',
            'launched_at' => '2026-10-06 12:34:56',
        ]);

        $response = $this->withSession(['test_process_success' => '<b>message</b>'])->get('/procesos?q=absent');

        $response->assertOk();
        $response->assertViewHas('managedProcesses', fn ($records): bool => $records->count() === 1 && $records->first()->id === $process->id);
        $response->assertSeeText('2026-10-06 12:34:56');
        $response->assertSee('<td class="numeric">42</td>', false);
        $response->assertSee('<td class="numeric">1000</td>', false);
        foreach ([$process->process_type, $process->command_label, $process->status, '<b>message</b>'] as $value) {
            $response->assertSee(e($value), false);
            $response->assertDontSee($value, false);
        }
    }

    public function test_missing_launcher_script_fails_without_creating_a_process(): void
    {
        $this->app->setBasePath('/tmp/sysmonitor-missing-launcher');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No se pudo lanzar el proceso de prueba.');

        (new TestProcessLauncher)->launch();
    }

    public function test_an_unavailable_registry_prevents_launch_and_keeps_the_page_usable(): void
    {
        // Exclusivamente SQLite en memoria, nunca la base local.
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        Schema::drop('managed_processes');
        $this->mock(TestProcessLauncher::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('launch');
        });
        $this->mockGeneralProcesses();

        $response = $this->post('/procesos/prueba');
        $response->assertRedirect(route('processes.index'));
        $response->assertSessionHas('test_process_error');

        $page = $this->get('/procesos');
        $page->assertOk();
        $page->assertSeeText('No se pudo consultar el registro de procesos de prueba.');
        $page->assertSeeText('No se pudo crear el proceso de prueba. Inténtalo nuevamente.');
        $page->assertViewHas('managedProcessesUnavailable', true);
        $page->assertDontSeeText('SQLSTATE');
        $document = new \DOMDocument;
        @$document->loadHTML($page->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//form[@class="launch-form"]//button[@disabled]')->length);
    }

    private function identity(): array
    {
        return ['pid' => 42, 'owner_uid' => 1000, 'start_time_ticks' => 12345];
    }

    private function mockLaunch(string $output, array $identity): void
    {
        $this->mock(TestProcessLauncher::class, function (MockInterface $mock) use ($output) {
            $mock->shouldReceive('launch')->once()->withNoArgs()->andReturn($output);
        });
        $this->mock(TestProcessIdentityReader::class, function (MockInterface $mock) use ($identity) {
            $mock->shouldReceive('read')->with(42)->once()->andReturn($identity);
        });
    }

    private function mockGeneralProcesses(): void
    {
        $this->partialMock(ProcessService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getProcesses')->once()->andReturn([]);
        });
    }
}
