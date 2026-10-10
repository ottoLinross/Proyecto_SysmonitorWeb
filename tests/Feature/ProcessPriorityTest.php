<?php

namespace Tests\Feature;

use App\Models\ManagedProcess;
use App\Services\System\ApplicationProcessUid;
use App\Services\System\ManagedProcessProvenance;
use App\Services\System\ProcessPriorityService;
use App\Services\System\ProcessReniceRunner;
use App\Services\System\ProcessService;
use App\Services\System\TestProcessIdentityReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ProcessPriorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(ApplicationProcessUid::class, function (MockInterface $mock) {
            $mock->shouldReceive('effectiveUid')->andReturn(1000);
        });
    }

    #[DataProvider('validValues')]
    public function test_valid_post_uses_registered_identity_and_verifies_nice(string $nice, int $exitCode): void
    {
        $process = $this->record('stopped');
        $this->mock(TestProcessIdentityReader::class, function (MockInterface $mock) use ($nice, $exitCode) {
            $mock->shouldReceive('read')->with(42)->once()->andReturn($this->identity());
            if ($exitCode === 0) {
                $mock->shouldReceive('readWithNice')->with(42)->once()->andReturn([...$this->identity(), 'nice' => (int) $nice]);
            } else {
                $mock->shouldNotReceive('readWithNice');
            }
        });
        $this->mock(ProcessReniceRunner::class, function (MockInterface $mock) use ($nice, $exitCode) {
            $mock->shouldReceive('run')->with(42, (int) $nice)->once()
                ->andReturn(['exit_code' => $exitCode, 'stdout' => '', 'stderr' => 'internal OS details']);
        });

        $response = $this->post(route('processes.test.priority', $process), ['nice' => $nice, 'pid' => 999, 'owner_uid' => 9999]);

        $response->assertRedirect(route('processes.index'));
        $response->assertSessionHas($exitCode === 0 ? 'test_process_success' : 'test_process_error',
            $exitCode === 0 ? 'Prioridad actualizada correctamente.' : 'No fue posible cambiar la prioridad del proceso.');
        $this->assertSame('stopped', $process->fresh()->status);
        $this->assertSame(42, $process->fresh()->pid);
        $this->assertSame(1000, $process->fresh()->owner_uid);
    }

    public static function validValues(): array
    {
        return [['10', 0], ['-20', 0], ['19', 0], ['-20', 1]];
    }

    #[DataProvider('invalidValues')]
    public function test_invalid_input_never_executes_renice(mixed $nice): void
    {
        $process = $this->record();
        $this->mock(ProcessReniceRunner::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('run');
        });
        $this->mock(TestProcessIdentityReader::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('read');
        });

        $response = $this->post(route('processes.test.priority', $process), ['nice' => $nice]);

        $response->assertRedirect(route('processes.index'));
        $response->assertSessionHas('test_process_error', 'El valor nice debe estar entre -20 y 19.');
    }

    public static function invalidValues(): array
    {
        return [[-21], [20], ['text'], ['10.5'], [10.5], ['1e1'], ['10; other-command'], [['10']], [null]];
    }

    #[DataProvider('identityErrors')]
    public function test_missing_or_mismatched_process_never_executes(bool $missing): void
    {
        $process = $this->record();
        $this->mock(TestProcessIdentityReader::class, function (MockInterface $mock) use ($missing) {
            if ($missing) {
                $mock->shouldReceive('read')->once()->andThrow(new RuntimeException('internal /proc details'));
                $mock->shouldReceive('exists')->with(42)->once()->andReturn(false);
            } else {
                $mock->shouldReceive('read')->once()->andReturn([...$this->identity(), 'start_time_ticks' => 67890]);
            }
        });
        $this->mock(ProcessReniceRunner::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('run');
        });

        $response = $this->post(route('processes.test.priority', $process), ['nice' => 10]);

        $response->assertRedirect(route('processes.index'));
        $response->assertSessionHas('test_process_error', $missing ? 'El proceso ya no existe.' : 'No se pudo validar la identidad del proceso.');
        $this->assertSame($missing ? 'missing' : 'identity_mismatch', $process->fresh()->status);
    }

    public static function identityErrors(): array
    {
        return [[true], [false]];
    }

    public function test_get_and_unregistered_url_ids_cannot_change_priority(): void
    {
        $process = $this->record();
        $this->mock(ProcessReniceRunner::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('run');
        });
        $this->assertNotSame($process->id, $process->pid);

        $this->get(route('processes.test.priority', $process))->assertStatus(405);
        $this->post('/procesos/prueba/42/priority', ['nice' => 10])->assertNotFound();
        $this->post('/procesos/prueba/invalid/priority', ['nice' => 10])->assertNotFound();
    }

    public function test_terminal_records_are_revalidated_and_rejected_by_the_backend(): void
    {
        $process = $this->record('killed');
        $this->mock(TestProcessIdentityReader::class, function (MockInterface $mock) {
            $mock->shouldReceive('read')->once()->andReturn($this->identity());
        });
        $this->mock(ProcessReniceRunner::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('run');
        });

        $this->post(route('processes.test.priority', $process), ['nice' => 10])
            ->assertRedirect(route('processes.index'))->assertSessionHas('test_process_error');
        $this->assertSame('killed', $process->fresh()->status);
    }

    public function test_forms_use_csrf_post_internal_ids_and_only_the_nice_field(): void
    {
        $active = $this->record();
        $terminal = $this->record('terminated', 43);
        $this->mockGeneralProcesses();

        $response = $this->get('/procesos');
        $response->assertOk();
        $response->assertSeeText('Cambiar prioridad');
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $forms = $xpath->query('//form[@class="priority-form"]');
        $this->assertSame(2, $forms->length);
        foreach ($forms as $form) {
            $this->assertSame('POST', $form->getAttribute('method'));
            $this->assertContains($form->getAttribute('action'), [route('processes.test.priority', $active), route('processes.test.priority', $terminal)]);
            $this->assertSame(1, $xpath->query('.//input[@name="_token"]', $form)->length);
            $this->assertSame(1, $xpath->query('.//input[@name="nice"][@type="number"][@min="-20"][@max="19"][@step="1"]', $form)->length);
            $this->assertSame(2, $xpath->query('.//input', $form)->length);
        }
        $this->assertSame(0, $xpath->query('//form[@class="priority-form"]//input[@name="pid"]')->length);
        $this->assertSame(1, $xpath->query('//form[@class="priority-form"]//button[@disabled]')->length);
    }

    public function test_priority_route_requires_csrf(): void
    {
        $process = $this->record();
        $this->mock(ProcessPriorityService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('change');
        });
        $this->app->detectEnvironment(fn () => 'local');

        $this->post(route('processes.test.priority', $process), ['nice' => 10])->assertStatus(419);
    }

    public function test_internal_errors_are_displayed_as_a_generic_message(): void
    {
        $process = $this->record();
        $this->mock(ProcessPriorityService::class, function (MockInterface $mock) {
            $mock->shouldReceive('change')->once()->andThrow(new RuntimeException('internal stderr'));
        });
        $this->mockGeneralProcesses();

        $response = $this->followingRedirects()->post(route('processes.test.priority', $process), ['nice' => 10]);

        $response->assertOk();
        $response->assertSeeText('No fue posible cambiar la prioridad del proceso.');
        $response->assertDontSeeText('internal stderr');
    }

    private function identity(): array
    {
        return ['pid' => 42, 'owner_uid' => 1000, 'start_time_ticks' => 12345];
    }

    private function record(string $status = 'running', int $pid = 42): ManagedProcess
    {
        $process = ManagedProcess::create([...$this->identity(), 'pid' => $pid, 'status' => $status,
            'process_type' => 'sleep', 'command_label' => '/usr/bin/sleep 300', 'launched_at' => now()]);
        $process->forceFill(['registration_signature' => app(ManagedProcessProvenance::class)->seal($process)])->save();

        return $process;
    }

    private function mockGeneralProcesses(): void
    {
        $this->partialMock(ProcessService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getProcesses')->once()->andReturn([]);
        });
    }
}
