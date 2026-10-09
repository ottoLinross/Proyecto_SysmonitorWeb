<?php

namespace Tests\Feature;

use App\Models\ManagedProcess;
use App\Services\System\ProcessService;
use App\Services\System\ProcessSignalSender;
use App\Services\System\ProcessSignalService;
use App\Services\System\TestProcessIdentityReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ProcessSignalTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('signals')]
    public function test_post_sends_a_whitelisted_signal_using_the_bound_record(string $signal, int $number, string $status): void
    {
        $process = $this->record($signal === 'cont' ? 'stopped' : 'running');
        $this->mock(TestProcessIdentityReader::class, function (MockInterface $mock) {
            $mock->shouldReceive('read')->with(42)->once()->andReturn($this->identity());
            $mock->shouldReceive('hasExited')->with(42, 12345)->andReturn(true);
        });
        $this->mock(ProcessSignalSender::class, function (MockInterface $mock) use ($number) {
            $mock->shouldReceive('send')->with(42, $number)->once()->andReturn(true);
        });

        $response = $this->post(route('processes.test.signal', $process), ['signal' => $signal, 'pid' => 999]);

        $response->assertRedirect(route('processes.index'));
        $response->assertSessionHas('test_process_success', ProcessSignalService::SIGNALS[$signal]['label'].' enviada correctamente al proceso de prueba.');
        $this->assertSame($status, $process->fresh()->status);
    }

    public static function signals(): array
    {
        return [['term', 15, 'terminated'], ['kill', 9, 'killed'], ['stop', 19, 'stopped'], ['cont', 18, 'running']];
    }

    #[DataProvider('invalidSignals')]
    public function test_invalid_signal_input_is_rejected_before_the_service(mixed $signal): void
    {
        $process = $this->record();
        $this->mock(ProcessSignalService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('send');
        });

        $response = $this->post(route('processes.test.signal', $process), ['signal' => $signal]);

        $response->assertRedirect(route('processes.index'));
        $response->assertSessionHas('test_process_error', 'Señal no permitida.');
        $this->assertSame('running', $process->fresh()->status);
    }

    public static function invalidSignals(): array
    {
        return [['9'], [9], ['USR1'], ['HUP'], ['ALL'], ['TERM'], [['kill']], [null], ['kill; arbitrary-command']];
    }

    public function test_get_cannot_send_a_signal(): void
    {
        $process = $this->record();
        $this->mock(ProcessSignalService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('send');
        });

        $this->get(route('processes.test.signal', $process))->assertStatus(405);
    }

    public function test_a_pid_in_the_url_does_not_substitute_for_an_internal_record_id(): void
    {
        $process = $this->record();
        $this->assertNotSame($process->id, $process->pid);
        $this->mock(ProcessSignalService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('send');
        });

        $this->post('/procesos/prueba/42/signal', ['signal' => 'kill'])->assertNotFound();
        $this->post('/procesos/prueba/invalid/signal', ['signal' => 'kill'])->assertNotFound();
    }

    public function test_a_missing_process_updates_the_record_and_returns_a_safe_message(): void
    {
        $process = $this->record();
        $this->mock(TestProcessIdentityReader::class, function (MockInterface $mock) {
            $mock->shouldReceive('read')->with(42)->once()->andThrow(new RuntimeException('internal /proc detail'));
            $mock->shouldReceive('exists')->with(42)->once()->andReturn(false);
        });
        $this->mock(ProcessSignalSender::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('send');
        });

        $response = $this->post(route('processes.test.signal', $process), ['signal' => 'term']);

        $response->assertRedirect(route('processes.index'));
        $response->assertSessionHas('test_process_error', 'El proceso ya no existe.');
        $this->assertSame('missing', $process->fresh()->status);
    }

    public function test_a_reused_pid_updates_status_without_sending(): void
    {
        $process = $this->record();
        $this->mock(TestProcessIdentityReader::class, function (MockInterface $mock) {
            $mock->shouldReceive('read')->with(42)->once()->andReturn([
                'pid' => 42, 'owner_uid' => 1000, 'start_time_ticks' => 67890,
            ]);
        });
        $this->mock(ProcessSignalSender::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('send');
        });

        $response = $this->post(route('processes.test.signal', $process), ['signal' => 'kill']);

        $response->assertRedirect(route('processes.index'));
        $response->assertSessionHas('test_process_error', 'No se pudo validar la identidad del proceso.');
        $this->assertSame('identity_mismatch', $process->fresh()->status);
    }

    public function test_terminal_records_cannot_be_signalled_even_if_a_button_is_bypassed(): void
    {
        $process = $this->record('terminated');
        $this->mock(TestProcessIdentityReader::class, function (MockInterface $mock) {
            $mock->shouldReceive('read')->with(42)->once()->andReturn($this->identity());
        });
        $this->mock(ProcessSignalSender::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('send');
        });

        $this->post(route('processes.test.signal', $process), ['signal' => 'kill'])
            ->assertRedirect(route('processes.index'))->assertSessionHas('test_process_error');
        $this->assertSame('terminated', $process->fresh()->status);
    }

    public function test_signal_forms_use_post_csrf_internal_ids_and_no_pid_fields(): void
    {
        $active = $this->record();
        $inactive = $this->record('missing', 43);
        $this->partialMock(ProcessService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getProcesses')->once()->andReturn([]);
        });

        $response = $this->get('/procesos');
        $response->assertOk();
        foreach (['SIGTERM', 'SIGKILL', 'SIGSTOP', 'SIGCONT'] as $label) {
            $response->assertSeeText($label);
        }
        $response->assertSeeText('missing');
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $forms = $xpath->query('//form[@class="signal-form"]');
        $this->assertSame(8, $forms->length);
        foreach ($forms as $form) {
            $this->assertSame('POST', $form->getAttribute('method'));
            $this->assertContains($form->getAttribute('action'), [route('processes.test.signal', $active), route('processes.test.signal', $inactive)]);
            $this->assertSame(1, $xpath->query('.//input[@name="_token"]', $form)->length);
            $this->assertSame(1, $xpath->query('.//input[@name="signal"][@type="hidden"]', $form)->length);
            $this->assertSame(2, $xpath->query('.//input', $form)->length);
        }
        $this->assertSame(0, $xpath->query('//form[@class="signal-form"]//input[@name="pid"]')->length);
        $this->assertSame(4, $xpath->query('//form[@class="signal-form"]//button[@disabled]')->length);
    }

    public function test_csrf_protects_the_signal_route(): void
    {
        $process = $this->record();
        $this->mock(ProcessSignalService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('send');
        });
        $this->app->detectEnvironment(fn () => 'local');

        $this->post(route('processes.test.signal', $process), ['signal' => 'kill'])->assertStatus(419);
    }

    public function test_internal_errors_are_not_exposed(): void
    {
        $process = $this->record();
        $this->mock(ProcessSignalService::class, function (MockInterface $mock) {
            $mock->shouldReceive('send')->once()->andThrow(new RuntimeException('internal signal detail'));
        });
        $this->partialMock(ProcessService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getProcesses')->once()->andReturn([]);
        });

        $response = $this->followingRedirects()->post(route('processes.test.signal', $process), ['signal' => 'stop']);

        $response->assertOk();
        $response->assertSeeText('No se pudo completar la acción del proceso de prueba.');
        $response->assertDontSeeText('internal signal detail');
    }

    private function identity(): array
    {
        return ['pid' => 42, 'owner_uid' => 1000, 'start_time_ticks' => 12345];
    }

    private function record(string $status = 'running', int $pid = 42): ManagedProcess
    {
        return ManagedProcess::create([
            ...$this->identity(), 'pid' => $pid, 'process_type' => 'sleep',
            'command_label' => '/usr/bin/sleep 300', 'status' => $status, 'launched_at' => now(),
        ]);
    }
}
