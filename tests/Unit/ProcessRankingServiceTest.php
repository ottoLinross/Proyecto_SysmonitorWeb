<?php

namespace Tests\Unit;

use App\Services\System\ProcessRankingService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProcessRankingServiceTest extends TestCase
{
    public function test_rankings_are_independent_descending_and_limited_to_five(): void
    {
        $processes = [];
        foreach ([3, 1, 6, 2, 5, 4] as $pid) {
            $processes[] = ['pid' => $pid, 'command' => 'process '.$pid, 'cpu_percent' => $pid * 25.0, 'memory_percent' => 7.0 - $pid];
        }
        $original = $processes;

        $rankings = (new ProcessRankingService)->getRankings($processes);

        $this->assertSame([6, 5, 4, 3, 2], array_column($rankings['cpu'], 'pid'));
        $this->assertSame([1, 2, 3, 4, 5], array_column($rankings['memory'], 'pid'));
        $this->assertSame($original, $processes);
        $this->assertSame($original[2], $rankings['cpu'][0]);
        $this->assertSame(150.0, $rankings['cpu'][0]['cpu_percent']);
    }

    public function test_ties_use_pid_ascending_for_both_rankings_including_the_cutoff(): void
    {
        $processes = [];
        foreach ([10, 2, 8, 1, 6, 4] as $pid) {
            $processes[] = ['pid' => $pid, 'command' => 'process '.$pid, 'cpu_percent' => 0.0, 'memory_percent' => 0.0];
        }

        $rankings = (new ProcessRankingService)->getRankings($processes);

        $this->assertSame([1, 2, 4, 6, 8], array_column($rankings['cpu'], 'pid'));
        $this->assertSame([1, 2, 4, 6, 8], array_column($rankings['memory'], 'pid'));
    }

    #[DataProvider('smallLists')]
    public function test_small_lists_are_not_padded(int $size): void
    {
        $processes = [];
        for ($pid = 1; $pid <= $size; $pid++) {
            $processes[] = ['pid' => $pid, 'command' => 'process '.$pid, 'cpu_percent' => 0.0, 'memory_percent' => 0.0];
        }

        $rankings = (new ProcessRankingService)->getRankings($processes);

        $this->assertSame($processes, $rankings['cpu']);
        $this->assertSame($processes, $rankings['memory']);
    }

    public static function smallLists(): array
    {
        return ['empty' => [0], 'single' => [1], 'less than five' => [3], 'exactly five' => [5]];
    }

    public function test_invalid_rows_duplicates_and_metrics_do_not_break_independent_rankings(): void
    {
        $rows = [
            null, 'invalid', [],
            ['pid' => 0, 'command' => 'invalid', 'cpu_percent' => 1.0, 'memory_percent' => 1.0],
            ['pid' => 1, 'command' => '', 'cpu_percent' => 1.0, 'memory_percent' => 1.0],
            ['pid' => 2, 'command' => 'valid CPU', 'cpu_percent' => 150.0, 'memory_percent' => INF],
            ['pid' => 3, 'command' => 'valid memory', 'cpu_percent' => NAN, 'memory_percent' => 10.0],
            ['pid' => 4, 'command' => 'invalid', 'cpu_percent' => -1.0, 'memory_percent' => 101.0],
            ['pid' => 5, 'command' => 'invalid', 'cpu_percent' => '1.0', 'memory_percent' => -1.0],
            ['pid' => 6, 'command' => 'valid', 'cpu_percent' => 2.0, 'memory_percent' => 5.0],
            ['pid' => 6, 'command' => 'duplicate', 'cpu_percent' => 3.0, 'memory_percent' => 6.0],
        ];

        $rankings = (new ProcessRankingService)->getRankings($rows);

        $this->assertSame([2, 6], array_column($rankings['cpu'], 'pid'));
        $this->assertSame([3, 6], array_column($rankings['memory'], 'pid'));
        $this->assertSame('valid', $rankings['cpu'][1]['command']);
    }
}
