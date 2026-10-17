<?php

namespace Tests\Unit;

use App\Services\Deadlock\BankerSafetyAnalyzer;
use App\Services\Deadlock\NeedCalculator;
use App\Services\Deadlock\ScenarioInput;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeadlockBankerSafetyTest extends TestCase
{
    public static function scenarios(): iterable
    {
        yield 'classic safe scenario' => [
            [[0, 1, 0], [2, 0, 0], [3, 0, 2], [2, 1, 1], [0, 0, 2]],
            [[7, 5, 3], [3, 2, 2], [9, 0, 2], [2, 2, 2], [4, 3, 3]],
            [3, 3, 2], [1, 3, 0, 2, 4], [], [10, 5, 7],
        ];
        yield 'restart search after releasing allocation' => [
            [[1], [1], [1]], [[2], [1], [1]], [0], [1, 0, 2], [], [3],
        ];
        yield 'all zero needs and resources' => [[[0], [0]], [[0], [0]], [0], [0, 1], [], [0]];
        yield 'equality is sufficient' => [[[0, 0]], [[2, 3]], [2, 3], [0], [], [2, 3]];
        yield 'one deficient resource blocks completion' => [[[0, 0]], [[0, 1]], [100, 0], [], [0], [100, 0]];
        yield 'no process can finish' => [[[1], [2]], [[2], [3]], [0], [], [0, 1], [0]];
        yield 'partial progress is not a safe sequence' => [[[1], [1], [0]], [[1], [3], [3]], [0], [0], [1, 2], [1]];
        yield 'zero allocation cannot release need' => [[[0], [0]], [[1], [2]], [1], [0], [1], [1]];
        yield 'upper bounds accumulate without clipping' => [
            array_fill(0, ScenarioInput::MAX_PROCESSES, array_fill(0, ScenarioInput::MAX_RESOURCES, ScenarioInput::MAX_VALUE)),
            array_fill(0, ScenarioInput::MAX_PROCESSES, array_fill(0, ScenarioInput::MAX_RESOURCES, ScenarioInput::MAX_VALUE)),
            array_fill(0, ScenarioInput::MAX_RESOURCES, ScenarioInput::MAX_VALUE),
            range(0, ScenarioInput::MAX_PROCESSES - 1), [],
            array_fill(0, ScenarioInput::MAX_RESOURCES, (ScenarioInput::MAX_PROCESSES + 1) * ScenarioInput::MAX_VALUE),
        ];
    }

    #[DataProvider('scenarios')]
    public function test_analysis_results_and_execution_invariants(array $allocation, array $max, array $available, array $completed, array $unfinished, array $finalWork): void
    {
        $scenario = (new ScenarioInput)->validate([
            'n' => count($allocation), 'm' => count($available),
            'allocation' => $allocation, 'max' => $max, 'available' => $available,
        ]);
        $before = serialize($scenario);
        $analyzer = new BankerSafetyAnalyzer(new NeedCalculator);
        $result = $analyzer->analyze($scenario);

        $this->assertSame($unfinished === [], $result->isSafe);
        $this->assertSame($unfinished === [] ? $completed : null, $result->safeSequence);
        $this->assertSame($completed, $result->completed);
        $this->assertSame($unfinished, $result->unfinished);
        $this->assertSame($finalWork, $result->work);
        $this->assertSame(array_map(fn ($i) => in_array($i, $completed, true), range(0, $scenario->n - 1)), $result->finish);
        $this->assertSame((new NeedCalculator)->calculate($scenario), $result->need);
        $this->assertSame($before, serialize($scenario));
        $this->assertEquals($result, $analyzer->analyze($scenario));

        // Replay the returned execution and independently verify resource availability.
        $work = $available;
        $finish = array_fill(0, $scenario->n, false);
        $this->assertCount(count($completed), $result->steps);
        foreach ($result->completed as $position => $i) {
            $step = $result->steps[$position];
            $this->assertSame($position + 1, $step->number);
            $this->assertSame($i, $step->process);
            $this->assertSame($work, $step->workBefore);
            $this->assertSame($result->need[$i], $step->need);
            $this->assertSame($allocation[$i], $step->allocation);
            foreach ($work as $j => $value) {
                $this->assertLessThanOrEqual($value, $max[$i][$j] - $allocation[$i][$j]);
                $work[$j] += $allocation[$i][$j];
            }
            $finish[$i] = true;
            $this->assertSame($work, $step->workAfter);
            $this->assertSame($finish, $step->finish);
        }
        $this->assertSame($finalWork, $work);
        $this->assertSame($result->finish, $finish);
        foreach ($unfinished as $i) {
            $blocked = false;
            foreach ($work as $j => $value) {
                $blocked = $blocked || $max[$i][$j] - $allocation[$i][$j] > $value;
            }
            $this->assertTrue($blocked);
        }
    }

    public function test_reusing_the_analyzer_does_not_carry_state_between_scenarios(): void
    {
        $input = new ScenarioInput;
        $analyzer = new BankerSafetyAnalyzer(new NeedCalculator);
        $safe = $input->validate(['n' => 1, 'm' => 1, 'allocation' => [[1]], 'max' => [[1]], 'available' => [0]]);
        $unsafe = $input->validate(['n' => 1, 'm' => 1, 'allocation' => [[0]], 'max' => [[1]], 'available' => [0]]);
        $this->assertTrue($analyzer->analyze($safe)->isSafe);
        $unsafeResult = $analyzer->analyze($unsafe);
        $this->assertFalse($unsafeResult->isSafe);
        $this->assertSame([], $unsafeResult->steps);
        $this->assertSame([0], $analyzer->analyze($safe)->safeSequence);
    }

    public function test_step_snapshots_capture_exact_vectors_without_later_mutation(): void
    {
        $scenario = (new ScenarioInput)->validate([
            'n' => 3, 'm' => 2,
            'allocation' => [[1, 2], [2, 0], [0, 1]],
            'max' => [[3, 2], [2, 1], [1, 2]],
            'available' => [0, 1],
        ]);
        $result = (new BankerSafetyAnalyzer(new NeedCalculator))->analyze($scenario);

        $this->assertSame([1, 0, 2], $result->safeSequence);
        $this->assertSame([
            [1, 1, [0, 1], [0, 1], [2, 0], [2, 1], [false, true, false]],
            [2, 0, [2, 1], [2, 0], [1, 2], [3, 3], [true, true, false]],
            [3, 2, [3, 3], [1, 1], [0, 1], [3, 4], [true, true, true]],
        ], array_map(fn ($step) => [
            $step->number, $step->process, $step->workBefore, $step->need,
            $step->allocation, $step->workAfter, $step->finish,
        ], $result->steps));
    }
}
