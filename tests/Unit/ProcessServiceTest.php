<?php

namespace Tests\Unit;

use App\Services\System\ProcessService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProcessServiceTest extends TestCase
{
    public function test_it_reads_the_current_linux_processes(): void
    {
        $processes = (new ProcessService)->getProcesses();

        $this->assertIsArray($processes);
        $this->assertNotEmpty($processes);
        $this->assertTrue(array_is_list($processes));

        foreach ($processes as $process) {
            $this->assertSame([
                'pid', 'ppid', 'user', 'state', 'nice',
                'cpu_percent', 'memory_percent', 'memory_kb', 'command',
            ], array_keys($process));
            $this->assertIsInt($process['pid']);
            $this->assertGreaterThan(0, $process['pid']);
            $this->assertIsInt($process['ppid']);
            $this->assertGreaterThanOrEqual(0, $process['ppid']);
            $this->assertIsInt($process['nice']);
            $this->assertIsFloat($process['cpu_percent']);
            $this->assertGreaterThanOrEqual(0, $process['cpu_percent']);
            $this->assertIsFloat($process['memory_percent']);
            $this->assertGreaterThanOrEqual(0, $process['memory_percent']);
            $this->assertIsInt($process['memory_kb']);
            $this->assertGreaterThanOrEqual(0, $process['memory_kb']);
            $this->assertNotSame('', trim($process['user']));
            $this->assertNotSame('', trim($process['state']));
            $this->assertNotSame('', trim($process['command']));
        }
    }

    public function test_it_preserves_commands_with_spaces_and_converts_numeric_fields(): void
    {
        $service = $this->serviceWithOutput(" 42 0 tester Sl+ -5 125.4 2.3 2048 /usr/bin/example --label two  words\n");

        $this->assertSame([[
            'pid' => 42,
            'ppid' => 0,
            'user' => 'tester',
            'state' => 'Sl+',
            'nice' => -5,
            'cpu_percent' => 125.4,
            'memory_percent' => 2.3,
            'memory_kb' => 2048,
            'command' => '/usr/bin/example --label two  words',
        ]], $service->getProcesses());
    }

    public function test_it_ignores_blank_and_invalid_lines(): void
    {
        $service = $this->serviceWithOutput(implode("\n", [
            '',
            '   ',
            'incomplete row',
            'PID PPID USER STAT NI %CPU %MEM RSS COMMAND',
            '0 0 tester S 0 0.0 0.0 10 example',
            '1 -1 tester S 0 0.0 0.0 10 example',
            '1 0 tester invalid 0 0.0 0.0 10 example',
            '1 0 tester S 20 0.0 0.0 10 example',
            '1 0 tester S 0 invalid 0.0 10 example',
            '1 0 tester S 0 0.0 -1.0 10 example',
            '1 0 tester S 0 0.0 0.0 -10 example',
            '1 0 tester S 0 0.0 0.0 10',
            '2 1 tester R 19 0.0 0.0 0 example',
            '',
        ]));

        $processes = $service->getProcesses();

        $this->assertCount(1, $processes);
        $this->assertSame(2, $processes[0]['pid']);
    }

    public function test_it_returns_an_empty_list_when_reading_fails_or_output_is_empty(): void
    {
        foreach ([null, '', "\n  \n"] as $output) {
            $this->assertSame([], $this->serviceWithOutput($output)->getProcesses());
        }
    }

    #[DataProvider('treeCases')]
    public function test_it_builds_a_safe_ordered_tree(array $input, array $expectedParents): void
    {
        $original = $input;
        $tree = (new ProcessService)->buildProcessTree($input);
        $expectedRoots = array_keys(array_filter($expectedParents, fn ($parent) => $parent === null));
        sort($expectedRoots, SORT_NUMERIC);
        $this->assertSame($expectedRoots, array_column($tree, 'pid'));

        $pending = [];
        foreach ($tree as $node) {
            $pending[] = [$node, null];
        }
        $actualParents = [];

        while ($pending !== []) {
            [$node, $parent] = array_pop($pending);
            $this->assertArrayNotHasKey($node['pid'], $actualParents, 'Cada PID debe aparecer una sola vez.');
            $actualParents[$node['pid']] = $parent;
            $this->assertLessThanOrEqual(count($input), count($actualParents));
            $children = array_column($node['children'], 'pid');
            $sorted = $children;
            sort($sorted, SORT_NUMERIC);
            $this->assertSame($sorted, $children);

            $process = $node;
            unset($process['children']);
            $this->assertContains($process, $input);

            foreach ($node['children'] as $child) {
                $pending[] = [$child, $node['pid']];
            }
        }

        ksort($actualParents, SORT_NUMERIC);
        ksort($expectedParents, SORT_NUMERIC);
        $this->assertSame($expectedParents, $actualParents);
        $this->assertSame($original, $input);
    }

    public static function treeCases(): array
    {
        return [
            'empty' => [[], []],
            'ppid zero' => [[['pid' => 1, 'ppid' => 0]], [1 => null]],
            'parent and child' => [[['pid' => 1, 'ppid' => 0], ['pid' => 2, 'ppid' => 1]], [1 => null, 2 => 1]],
            'unordered children and three levels' => [[
                ['pid' => 4, 'ppid' => 2], ['pid' => 3, 'ppid' => 1],
                ['pid' => 2, 'ppid' => 1], ['pid' => 1, 'ppid' => 0],
            ], [1 => null, 2 => 1, 3 => 1, 4 => 2]],
            'missing parent and ordered roots' => [[
                ['pid' => 9, 'ppid' => 999], ['pid' => 2, 'ppid' => 0], ['pid' => 5, 'ppid' => 998],
            ], [2 => null, 5 => null, 9 => null]],
            'self parent with descendant' => [[['pid' => 1, 'ppid' => 1], ['pid' => 2, 'ppid' => 1]], [1 => null, 2 => 1]],
            'two member cycle with descendant' => [[
                ['pid' => 3, 'ppid' => 2], ['pid' => 2, 'ppid' => 1], ['pid' => 1, 'ppid' => 2],
            ], [1 => null, 2 => null, 3 => 2]],
            'three member cycle reached from descendant' => [[
                ['pid' => 1, 'ppid' => 4], ['pid' => 4, 'ppid' => 3],
                ['pid' => 3, 'ppid' => 2], ['pid' => 2, 'ppid' => 4],
            ], [1 => 4, 2 => null, 3 => null, 4 => null]],
            'invalid parent' => [[
                ['pid' => 1, 'ppid' => -1], ['pid' => 2, 'ppid' => null], ['pid' => 3, 'ppid' => 'invalid'],
            ], [1 => null, 2 => null, 3 => null]],
        ];
    }

    public function test_duplicate_and_invalid_pids_do_not_duplicate_nodes(): void
    {
        $tree = (new ProcessService)->buildProcessTree([
            ['pid' => 0, 'ppid' => 0],
            ['pid' => 'invalid', 'ppid' => 0],
            ['pid' => 1, 'ppid' => 0, 'command' => 'original'],
            ['pid' => 1, 'ppid' => 0, 'command' => 'duplicate'],
        ]);

        $this->assertSame([['pid' => 1, 'ppid' => 0, 'command' => 'original', 'children' => []]], $tree);
    }

    public function test_deep_trees_are_built_without_recursion(): void
    {
        $processes = [];
        for ($pid = 2000; $pid >= 1; $pid--) {
            $processes[] = ['pid' => $pid, 'ppid' => $pid - 1];
        }

        $tree = (new ProcessService)->buildProcessTree($processes);
        $count = 0;
        while ($tree !== []) {
            $this->assertCount(1, $tree);
            $this->assertSame(++$count, $tree[0]['pid']);
            $tree = $tree[0]['children'];
        }

        $this->assertSame(2000, $count);
    }

    private function serviceWithOutput(?string $output): ProcessService
    {
        return new class($output) extends ProcessService
        {
            public function __construct(private readonly ?string $output) {}

            protected function readProcessOutput(): ?string
            {
                return $this->output;
            }
        };
    }
}
