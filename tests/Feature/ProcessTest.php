<?php

namespace Tests\Feature;

use App\Services\System\ProcessService;
use Mockery\MockInterface;
use Tests\TestCase;

class ProcessTest extends TestCase
{
    public function test_processes_page_displays_the_process_table(): void
    {
        $process = $this->exampleProcess();
        $this->mock(ProcessService::class, function (MockInterface $mock) use ($process) {
            $mock->shouldReceive('getProcesses')->once()->andReturn([$process]);
        });

        $response = $this->get('/procesos');

        $response->assertStatus(200);
        $response->assertSeeText('Módulo de Procesos');
        $response->assertViewIs('processes.index');
        $response->assertViewHas('processes', [$process]);

        foreach (['PID', 'PPID', 'Usuario', 'Estado', 'Nice', 'CPU %', 'Memoria %', 'Memoria RSS (KB)', 'Comando'] as $heading) {
            $response->assertSeeText($heading);
        }

        foreach (['4312', '123', '-5', '12.5', '1.2', '2048'] as $value) {
            $response->assertSee('<td class="numeric">'.$value.'</td>', false);
        }

        $response->assertSeeText($process['user']);
        $response->assertSeeText($process['state']);
        $response->assertSeeText($process['command']);
        $response->assertDontSeeText('No hay procesos disponibles para mostrar.');
    }

    public function test_processes_page_handles_an_empty_list(): void
    {
        $this->mock(ProcessService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getProcesses')->once()->andReturn([]);
        });

        $response = $this->get('/procesos');

        $response->assertStatus(200);
        $response->assertSeeText('Módulo de Procesos');
        $response->assertViewHas('processes', []);
        $response->assertSeeText('No hay procesos disponibles para mostrar.');
        $response->assertSee('<td colspan="9" class="empty">', false);
    }

    public function test_processes_page_escapes_system_values(): void
    {
        $process = array_replace($this->exampleProcess(), [
            'user' => '<b>usuario</b>',
            'state' => '<i>S</i>',
            'command' => '/usr/bin/example --label "two words" <script>alert("test")</script>',
        ]);
        $this->mock(ProcessService::class, function (MockInterface $mock) use ($process) {
            $mock->shouldReceive('getProcesses')->once()->andReturn([$process]);
        });

        $response = $this->get('/procesos');

        $response->assertStatus(200);

        foreach (['user', 'state', 'command'] as $field) {
            $response->assertSee(e($process[$field]), false);
            $response->assertDontSee($process[$field], false);
        }
    }

    private function exampleProcess(): array
    {
        return [
            'pid' => 4312,
            'ppid' => 123,
            'user' => 'usuario-ejemplo',
            'state' => 'Sl+',
            'nice' => -5,
            'cpu_percent' => 12.5,
            'memory_percent' => 1.2,
            'memory_kb' => 2048,
            'command' => '/usr/bin/example --label "two  words"',
        ];
    }
}
