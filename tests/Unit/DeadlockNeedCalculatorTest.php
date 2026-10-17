<?php

namespace Tests\Unit;

use App\Services\Deadlock\NeedCalculator;
use App\Services\Deadlock\ScenarioInput;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeadlockNeedCalculatorTest extends TestCase
{
    public static function scenarios(): iterable
    {
        yield 'rectangular matrix preserves both orders' => [
            [[1, 0, 3], [2, 4, 1]], [[5, 2, 6], [3, 4, 8]], [[4, 2, 3], [1, 0, 7]],
        ];
        yield 'all zero' => [[[0, 0], [0, 0]], [[0, 0], [0, 0]], [[0, 0], [0, 0]]];
        yield 'fully allocated' => [[[4, 2], [1, 3]], [[4, 2], [1, 3]], [[0, 0], [0, 0]]];
        yield 'minimum dimensions' => [[[0]], [[ScenarioInput::MAX_VALUE]], [[ScenarioInput::MAX_VALUE]]];
        yield 'single resource' => [[[1], [0], [5]], [[4], [2], [5]], [[3], [2], [0]]];
        yield 'single process' => [[[1, 0, 5]], [[4, 2, 5]], [[3, 2, 0]]];

        $zeros = array_fill(0, ScenarioInput::MAX_PROCESSES, array_fill(0, ScenarioInput::MAX_RESOURCES, 0));
        $maximums = array_fill(0, ScenarioInput::MAX_PROCESSES, array_fill(0, ScenarioInput::MAX_RESOURCES, ScenarioInput::MAX_VALUE));
        yield 'maximum dimensions and values' => [$zeros, $maximums, $maximums];
    }

    #[DataProvider('scenarios')]
    public function test_calculates_need_from_validated_scenarios(array $allocation, array $max, array $expected): void
    {
        $scenario = (new ScenarioInput)->validate([
            'n' => count($max),
            'm' => count($max[0]),
            'allocation' => $allocation,
            'max' => $max,
            'available' => array_fill(0, count($max[0]), 0),
        ]);
        $before = serialize($scenario);
        $calculator = new NeedCalculator;

        $this->assertSame($expected, $calculator->calculate($scenario));
        $this->assertSame($before, serialize($scenario));
        $this->assertSame($expected, $calculator->calculate($scenario));
    }
}
