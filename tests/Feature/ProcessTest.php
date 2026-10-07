<?php

namespace Tests\Feature;

use App\Services\System\ProcessService;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[DataProvider('searchQueries')]
    public function test_it_searches_every_visible_column(string $query): void
    {
        $processes = $this->sampleProcesses();
        $this->mockProcesses($processes);

        $response = $this->get('/procesos?'.http_build_query(['q' => $query]));

        $response->assertOk();
        $response->assertViewHas('processes', [$processes[0]]);
        $response->assertSeeText($processes[0]['command']);
        $response->assertDontSeeText($processes[1]['command']);
    }

    public static function searchQueries(): array
    {
        return [
            'pid' => ['4312'],
            'ppid' => ['123'],
            'user, partial and case insensitive' => ['USUARIO-EJ'],
            'state' => ['sl+'],
            'nice' => ['-5'],
            'cpu' => ['12.5'],
            'memory percentage' => ['1.2'],
            'rss' => ['2048'],
            'command, partial and case insensitive' => ['EXAMPLE --LABEL'],
        ];
    }

    public function test_it_distinguishes_no_matches_from_an_empty_service(): void
    {
        $this->mockProcesses($this->sampleProcesses());

        $response = $this->get('/procesos?q=sin-coincidencias');

        $response->assertOk();
        $response->assertViewHas('processes', []);
        $response->assertSeeText('No se encontraron procesos que coincidan con la búsqueda.');
        $response->assertDontSeeText('No hay procesos disponibles para mostrar.');
    }

    public function test_an_empty_service_with_a_search_still_shows_the_empty_service_message(): void
    {
        $this->mockProcesses([]);

        $response = $this->get('/procesos?q=example');

        $response->assertOk();
        $response->assertSeeText('No hay procesos disponibles para mostrar.');
        $response->assertDontSeeText('No se encontraron procesos que coincidan con la búsqueda.');
    }

    #[DataProvider('sortOptions')]
    public function test_it_sorts_every_column_in_both_directions(string $sort, string $direction, array $expectedPids): void
    {
        $processes = $this->sampleProcesses();
        $this->mockProcesses($processes);

        $response = $this->get('/procesos?'.http_build_query(compact('sort', 'direction')));

        $expected = array_map(fn (int $pid): array => $pid === 4312 ? $processes[0] : $processes[1], $expectedPids);
        $response->assertOk();
        $response->assertViewHas('processes', $expected);
        $response->assertSeeTextInOrder(array_column($expected, 'command'));
        $response->assertViewHas('sort', $sort);
        $response->assertViewHas('direction', $direction);
        $response->assertSee('aria-sort="'.($direction === 'asc' ? 'ascending' : 'descending').'"', false);
    }

    public static function sortOptions(): array
    {
        $options = [];

        foreach ([
            'pid' => [12, 4312],
            'ppid' => [12, 4312],
            'user' => [4312, 12],
            'state' => [12, 4312],
            'nice' => [4312, 12],
            'cpu_percent' => [12, 4312],
            'memory_percent' => [4312, 12],
            'memory_kb' => [12, 4312],
            'command' => [4312, 12],
        ] as $column => $pids) {
            $options[$column.' asc'] = [$column, 'asc', $pids];
            $options[$column.' desc'] = [$column, 'desc', array_reverse($pids)];
        }

        return $options;
    }

    public function test_it_defaults_to_pid_ascending(): void
    {
        $processes = $this->sampleProcesses();
        $this->mockProcesses($processes);

        $response = $this->get('/procesos');

        $response->assertOk();
        $response->assertViewHas('processes', [$processes[1], $processes[0]]);
        $response->assertViewHas('sort', 'pid');
        $response->assertViewHas('direction', 'asc');
    }

    #[DataProvider('invalidSorts')]
    public function test_invalid_sort_uses_the_safe_default(mixed $sort): void
    {
        $processes = $this->sampleProcesses();
        $this->mockProcesses($processes);

        $response = $this->get('/procesos?'.http_build_query(['sort' => $sort, 'direction' => 'desc']));

        $response->assertOk();
        $response->assertViewHas('sort', 'pid');
        $response->assertViewHas('direction', 'asc');
        $response->assertViewHas('processes', [$processes[1], $processes[0]]);
    }

    public static function invalidSorts(): array
    {
        return [
            'unknown' => ['unknown'],
            'command fragment' => ['pid; arbitrary-command'],
            'array' => [['pid']],
        ];
    }

    #[DataProvider('invalidDirections')]
    public function test_invalid_direction_defaults_to_ascending(mixed $direction): void
    {
        $processes = $this->sampleProcesses();
        $this->mockProcesses($processes);

        $response = $this->get('/procesos?'.http_build_query(['sort' => 'cpu_percent', 'direction' => $direction]));

        $response->assertOk();
        $response->assertViewHas('sort', 'cpu_percent');
        $response->assertViewHas('direction', 'asc');
        $response->assertViewHas('processes', [$processes[1], $processes[0]]);
    }

    public static function invalidDirections(): array
    {
        return ['unknown' => ['invalid'], 'uppercase' => ['DESC'], 'array' => [['desc']]];
    }

    #[DataProvider('emptyQueries')]
    public function test_empty_or_non_text_queries_show_all_processes(mixed $query): void
    {
        $processes = $this->sampleProcesses();
        $this->mockProcesses($processes);

        $response = $this->get('/procesos?'.http_build_query(['q' => $query]));

        $response->assertOk();
        $response->assertViewHas('q', '');
        $response->assertViewHas('processes', [$processes[1], $processes[0]]);
    }

    public static function emptyQueries(): array
    {
        return ['empty' => [''], 'whitespace' => ['   '], 'array' => [['example']]];
    }

    public function test_sort_links_preserve_search_and_toggle_direction(): void
    {
        $this->mockProcesses($this->sampleProcesses());
        $query = 'EXAMPLE --label "two  words"';

        $response = $this->get('/procesos?'.http_build_query(['q' => '  '.$query.'  ', 'sort' => 'command', 'direction' => 'asc']));

        $response->assertOk();
        $response->assertViewHas('q', $query);
        $response->assertSee('name="q" value="'.e($query).'"', false);
        $response->assertSee('name="sort" value="command"', false);
        $response->assertSee('name="direction" value="asc"', false);

        foreach (['pid', 'ppid', 'user', 'state', 'nice', 'cpu_percent', 'memory_percent', 'memory_kb', 'command'] as $column) {
            $url = route('processes.index', ['q' => $query, 'sort' => $column, 'direction' => $column === 'command' ? 'desc' : 'asc']);
            $response->assertSee('href="'.e($url).'"', false);
        }

        $response->assertSee('href="'.e(route('processes.index', ['sort' => 'command', 'direction' => 'asc'])).'"', false);
        $response->assertSeeText('Limpiar búsqueda');
        $response->assertSeeText('↑');
    }

    public function test_descending_column_link_toggles_back_to_ascending(): void
    {
        $this->mockProcesses($this->sampleProcesses());

        $response = $this->get('/procesos?q=example&sort=pid&direction=desc');

        $response->assertOk();
        $response->assertSee('href="'.e(route('processes.index', ['q' => 'example', 'sort' => 'pid', 'direction' => 'asc'])).'"', false);
        $response->assertSeeText('↓');
    }

    public function test_search_parameters_are_escaped_in_the_form_and_sort_links(): void
    {
        $this->mockProcesses($this->sampleProcesses());
        $query = '"><script>alert("test")</script>&';

        $response = $this->get('/procesos?'.http_build_query(['q' => $query]));

        $response->assertOk();
        $response->assertSee('name="q" value="'.e($query).'"', false);
        $response->assertDontSee($query, false);
        $response->assertDontSee('<script>', false);
        $response->assertSee('href="'.e(route('processes.index', ['q' => $query, 'sort' => 'pid', 'direction' => 'desc'])).'"', false);
    }

    private function mockProcesses(array $processes): void
    {
        $this->mock(ProcessService::class, function (MockInterface $mock) use ($processes) {
            $mock->shouldReceive('getProcesses')->once()->andReturn($processes);
        });
    }

    private function sampleProcesses(): array
    {
        return [
            $this->exampleProcess(),
            [
                'pid' => 12,
                'ppid' => 3,
                'user' => 'Zebra',
                'state' => 'R',
                'nice' => 19,
                'cpu_percent' => 2.1,
                'memory_percent' => 10.0,
                'memory_kb' => 900,
                'command' => '/usr/bin/zebra --background',
            ],
        ];
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
