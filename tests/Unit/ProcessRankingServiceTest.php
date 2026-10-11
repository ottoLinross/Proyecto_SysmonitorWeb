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
}
