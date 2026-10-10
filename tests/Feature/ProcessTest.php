<?php

namespace Tests\Feature;

use App\Services\System\ProcessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProcessTest extends TestCase
{
    use RefreshDatabase;

    public function test_processes_page_displays_the_process_table(): void
    {
        $process = $this->exampleProcess();
        $this->partialMock(ProcessService::class, function (MockInterface $mock) use ($process) {
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
        $this->partialMock(ProcessService::class, function (MockInterface $mock) {
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
        $this->partialMock(ProcessService::class, function (MockInterface $mock) use ($process) {
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
        $this->assertStringNotContainsString(e($processes[1]['command']), $this->tableHtml($response));
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

    public function test_state_summary_counts_all_five_states_and_their_modifiers(): void
    {
        $processes = $this->processesForSummary();
        $this->mockProcesses($processes);

        $response = $this->get('/procesos');

        $response->assertOk();
        $this->assertStateSummary($response, ['R' => 2, 'S' => 3, 'D' => 1, 'Z' => 1, 'T' => 2]);
        $response->assertViewHas('processes', $processes);
        $response->assertSeeText('Resumen por estados');

        foreach (['R - Ejecutándose', 'S - Dormidos', 'D - Espera no interrumpible', 'Z - Zombies', 'T - Detenidos'] as $label) {
            $response->assertSeeText($label);
        }

        foreach (['Ss', 'S+', 'R+', 'Tl'] as $state) {
            $response->assertSee('<td>'.$state.'</td>', false);
        }
    }

    public function test_state_summary_shows_zero_counts_when_the_service_is_empty(): void
    {
        $this->mockProcesses([]);

        $response = $this->get('/procesos');

        $response->assertOk();
        $this->assertStateSummary($response, ['R' => 0, 'S' => 0, 'D' => 0, 'Z' => 0, 'T' => 0]);
        $response->assertSeeText('No hay procesos disponibles para mostrar.');
    }

    #[DataProvider('ignoredStates')]
    public function test_unknown_empty_and_null_states_do_not_break_the_page(?string $state): void
    {
        $processes = [
            array_replace($this->exampleProcess(), ['pid' => 10, 'state' => $state]),
            array_replace($this->exampleProcess(), ['pid' => 11, 'state' => 'R']),
        ];
        $this->mockProcesses($processes);

        // Ordenar por estado también debe tolerar un valor null.
        $response = $this->get('/procesos?sort=state&direction=asc');

        $response->assertOk();
        $this->assertStateSummary($response, ['R' => 1, 'S' => 0, 'D' => 0, 'Z' => 0, 'T' => 0]);
        $response->assertViewHas('processes', function (array $visible) use ($state): bool {
            return count($visible) === 2
                && collect($visible)->firstWhere('pid', 10)['state'] === $state;
        });
    }

    public static function ignoredStates(): array
    {
        return [
            'unknown' => ['?'],
            'other Linux state' => ['I'],
            'lowercase stopped' => ['t'],
            'empty' => [''],
            'null' => [null],
        ];
    }

    #[DataProvider('summaryTableOptions')]
    public function test_search_and_sort_do_not_change_the_global_summary(array $parameters, array $expectedPids): void
    {
        $processes = $this->processesForSummary();
        $this->mockProcesses($processes);

        $response = $this->get('/procesos?'.http_build_query($parameters));

        $response->assertOk();
        $this->assertStateSummary($response, ['R' => 2, 'S' => 3, 'D' => 1, 'Z' => 1, 'T' => 2]);
        $response->assertViewHas('processes', function (array $visible) use ($expectedPids): bool {
            return array_column($visible, 'pid') === $expectedPids;
        });
    }

    public static function summaryTableOptions(): array
    {
        return [
            'filtered table' => [['q' => 'summary-12'], [12]],
            'no matches' => [['q' => 'no-matches'], []],
            'pid descending' => [['sort' => 'pid', 'direction' => 'desc'], [18, 17, 16, 15, 14, 13, 12, 11, 10]],
            'cpu ascending' => [['sort' => 'cpu_percent', 'direction' => 'asc'], [10, 11, 12, 13, 14, 15, 16, 17, 18]],
            'state descending' => [['sort' => 'state', 'direction' => 'desc'], [16, 18, 17, 13, 14, 12, 11, 10, 15]],
            'search and sort together' => [['q' => 'target', 'sort' => 'cpu_percent', 'direction' => 'desc'], [16, 10]],
        ];
    }

    private function assertStateSummary(TestResponse $response, array $expected): void
    {
        $response->assertViewHas('stateSummary', $expected);

        foreach ($expected as $state => $count) {
            $response->assertSee('<dd id="state-count-'.$state.'">'.$count.'</dd>', false);
        }
    }

    private function processesForSummary(): array
    {
        $processes = [];

        foreach (['R', 'R+', 'S', 'Ss', 'S+', 'D', 'Z', 'T', 'Tl'] as $index => $state) {
            $processes[] = array_replace($this->exampleProcess(), [
                'pid' => 10 + $index,
                'state' => $state,
                'user' => in_array($index, [0, 6], true) ? 'summary-target' : 'worker',
                'cpu_percent' => (float) $index,
                'command' => '/usr/bin/summary-'.(10 + $index),
            ]);
        }

        return $processes;
    }

    #[DataProvider('treeTableOptions')]
    public function test_the_global_tree_is_rendered_independently_of_table_parameters(array $parameters, array $expectedTablePids): void
    {
        $processes = $this->treeProcesses();
        $this->mockProcesses($processes);
        $byPid = array_column($processes, null, 'pid');
        $expectedTree = [
            array_replace($byPid[5], ['children' => []]),
            array_replace($byPid[10], ['children' => [
                array_replace($byPid[20], ['children' => [array_replace($byPid[40], ['children' => []])]]),
                array_replace($byPid[30], ['children' => []]),
            ]]),
        ];

        $response = $this->get('/procesos?'.http_build_query($parameters));

        $response->assertOk();
        $response->assertSeeText('Árbol de Procesos');
        $response->assertViewHas('processTree', $expectedTree);
        $response->assertViewHas('processes', fn (array $visible): bool => array_column($visible, 'pid') === $expectedTablePids);
        $this->assertStateSummary($response, ['R' => 0, 'S' => 5, 'D' => 0, 'Z' => 0, 'T' => 0]);

        foreach ($processes as $process) {
            $response->assertSeeText('PID '.$process['pid']);
            $response->assertSee('class="tree-command">'.e($process['command']).'</span>', false);
            $this->assertSame(1, substr_count($response->getContent(), 'data-tree-pid="'.$process['pid'].'"'));
        }

        // Comprobar la jerarquía HTML real, además de la estructura entregada a Blade.
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame('10', $xpath->evaluate('string(//li[@data-tree-pid="20"]/parent::ul/parent::li/@data-tree-pid)'));
        $this->assertSame('20', $xpath->evaluate('string(//li[@data-tree-pid="40"]/parent::ul/parent::li/@data-tree-pid)'));
        $this->assertSame('10', $xpath->evaluate('string(//li[@data-tree-pid="30"]/parent::ul/parent::li/@data-tree-pid)'));
    }

    public static function treeTableOptions(): array
    {
        return [
            'default' => [[], [5, 10, 20, 30, 40]],
            'search leaves one table row' => [['q' => 'tree-40'], [40]],
            'search leaves no table rows' => [['q' => 'absent'], []],
            'pid descending' => [['sort' => 'pid', 'direction' => 'desc'], [40, 30, 20, 10, 5]],
            'command ascending' => [['sort' => 'command', 'direction' => 'asc'], [10, 20, 30, 40, 5]],
            'search and sort' => [['q' => 'tree-40', 'sort' => 'command', 'direction' => 'desc'], [40]],
        ];
    }

    public function test_the_empty_tree_displays_a_friendly_message(): void
    {
        $this->mockProcesses([]);

        $response = $this->get('/procesos');

        $response->assertOk();
        $response->assertViewHas('processTree', []);
        $response->assertSeeText('Árbol de Procesos');
        $response->assertSeeText('No hay procesos disponibles para construir el árbol.');
        $response->assertDontSee('data-tree-pid=', false);
    }

    public function test_the_tree_escapes_commands_users_and_states(): void
    {
        $process = array_replace($this->exampleProcess(), [
            'ppid' => 0,
            'command' => '<script>alert("tree")</script> --label "two words"',
            'user' => '<b>tree-user</b>',
            'state' => '<i>S</i>',
        ]);
        $this->mockProcesses([$process]);

        $response = $this->get('/procesos');

        $response->assertOk();
        $response->assertSee('class="tree-command">'.e($process['command']).'</span>', false);
        $response->assertSee('Usuario: '.e($process['user']).' · Estado: '.e($process['state']), false);
        foreach (['command', 'user', 'state'] as $field) {
            $response->assertDontSee($process[$field], false);
        }
    }

    #[DataProvider('cyclicTreeParents')]
    public function test_anomalous_relations_render_each_process_once(array $parents): void
    {
        $processes = [];
        foreach ($parents as $pid => $ppid) {
            $processes[] = array_replace($this->exampleProcess(), ['pid' => $pid, 'ppid' => $ppid]);
        }
        $this->mockProcesses($processes);

        $response = $this->get('/procesos');

        $response->assertOk();
        foreach (array_keys($parents) as $pid) {
            $this->assertSame(1, substr_count($response->getContent(), 'data-tree-pid="'.$pid.'"'));
        }
    }

    public static function cyclicTreeParents(): array
    {
        return ['self parent' => [[10 => 10]], 'cycle with descendant' => [[10 => 20, 20 => 10, 30 => 20]]];
    }

    public function test_a_deep_tree_renders_without_recursive_blade_calls(): void
    {
        $processes = [];
        for ($pid = 1; $pid <= 400; $pid++) {
            $processes[] = array_replace($this->exampleProcess(), ['pid' => $pid, 'ppid' => $pid - 1]);
        }
        $this->mockProcesses($processes);

        $response = $this->get('/procesos');

        $response->assertOk();
        $this->assertSame(400, substr_count($response->getContent(), 'data-tree-pid="'));
        $response->assertSee('data-tree-pid="400"', false);
    }

    private function treeProcesses(): array
    {
        $processes = [];
        foreach ([30 => 10, 40 => 20, 5 => 999, 20 => 10, 10 => 0] as $pid => $ppid) {
            $processes[] = array_replace($this->exampleProcess(), [
                'pid' => $pid,
                'ppid' => $ppid,
                'command' => '/usr/bin/tree-'.$pid,
            ]);
        }

        return $processes;
    }

    private function tableHtml(TestResponse $response): string
    {
        $this->assertSame(1, preg_match('/<table\b[^>]*>.*?<\/table>/s', $response->getContent(), $matches));

        return $matches[0];
    }

    private function mockProcesses(array $processes): void
    {
        $this->partialMock(ProcessService::class, function (MockInterface $mock) use ($processes) {
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
