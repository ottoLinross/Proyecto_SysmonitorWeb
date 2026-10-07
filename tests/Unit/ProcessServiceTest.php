<?php

namespace Tests\Unit;

use App\Services\System\ProcessService;
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
