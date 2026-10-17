<?php

namespace Tests\Unit;

use App\Services\Deadlock\BankerSafetyAnalyzer;
use App\Services\Deadlock\NeedCalculator;
use App\Services\Deadlock\ResourceRequestEvaluator;
use App\Services\Deadlock\ResourceRequestInput;
use App\Services\Deadlock\ResourceRequestResult;
use App\Services\Deadlock\ScenarioInput;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeadlockResourceRequestTest extends TestCase
{
    public static function decisions(): iterable
    {
        // Allocation, Max, Available, process (one-based), Request, decision.
        yield 'safe grant' => [[[1, 0], [0, 1]], [[2, 1], [1, 2]], [2, 2], 2, [1, 0], 'granted'];
        yield 'need before availability across all resources' => [[[0, 0]], [[1, 0]], [0, 0], 1, [1, 1], 'invalid'];
        yield 'need exceeded' => [[[0]], [[0]], [1], 1, [1], 'invalid'];
        yield 'available insufficient' => [[[0]], [[2]], [0], 1, [1], 'wait'];
        yield 'unsafe temporary state' => [[[1], [1]], [[3], [2]], [1], 1, [1], 'unsafe'];
        yield 'request equal to need and available' => [[[0]], [[2]], [2], 1, [2], 'granted'];
        yield 'zero request' => [[[0]], [[1]], [1], 1, [0], 'granted'];
        yield 'zero request does not bypass safety' => [[[0]], [[1]], [0], 1, [0], 'unsafe'];
        yield 'maximum value' => [[[0]], [[ScenarioInput::MAX_VALUE]], [ScenarioInput::MAX_VALUE], 1, [ScenarioInput::MAX_VALUE], 'granted'];
        $matrix = array_fill(0, ScenarioInput::MAX_PROCESSES, array_fill(0, ScenarioInput::MAX_RESOURCES, 0));
        yield 'maximum process and resource count' => [$matrix, $matrix, array_fill(0, ScenarioInput::MAX_RESOURCES, 0), ScenarioInput::MAX_PROCESSES, array_fill(0, ScenarioInput::MAX_RESOURCES, 0), 'granted'];
    }

    #[DataProvider('decisions')]
    public function test_decisions_and_original_data_are_preserved(array $allocation, array $max, array $available, int $process, array $vector, string $decision): void
    {
        $input = new ScenarioInput;
        $scenario = $input->validate(['n' => count($allocation), 'm' => count($available), 'allocation' => $allocation, 'max' => $max, 'available' => $available]);
        $request = (new ResourceRequestInput($input))->validate(['process' => $process, 'request' => $vector], $scenario);
        $before = serialize($scenario);
        $calculator = new NeedCalculator;
        $analyzer = new BankerSafetyAnalyzer($calculator);
        $evaluator = new ResourceRequestEvaluator($calculator, $analyzer);
        $result = $evaluator->evaluate($scenario, $request);

        $this->assertSame($decision, $result->decision);
        $this->assertSame($vector, $result->request->vector);
        $this->assertSame($process - 1, $result->request->process);
        $this->assertSame($before, serialize($scenario));
        $this->assertEquals($result, $evaluator->evaluate($scenario, $request));
        if ($decision === ResourceRequestResult::GRANTED) {
            $this->assertNotSame($scenario, $result->simulated);
            $this->assertTrue($result->safety->isSafe);
            $this->assertSame($max, $result->simulated->max);
            foreach ($vector as $j => $value) {
                $this->assertSame($available[$j] - $value, $result->simulated->available[$j]);
                $this->assertSame($allocation[$process - 1][$j] + $value, $result->simulated->allocation[$process - 1][$j]);
                $this->assertSame($max[$process - 1][$j] - $allocation[$process - 1][$j] - $value, $result->safety->need[$process - 1][$j]);
            }
            foreach ($allocation as $i => $row) {
                if ($i !== $process - 1) {
                    $this->assertSame($row, $result->simulated->allocation[$i]);
                }
            }
        } else {
            $this->assertNull($result->simulated);
            if ($decision === ResourceRequestResult::UNSAFE) {
                $this->assertFalse($result->safety->isSafe);
            } else {
                $this->assertNull($result->safety);
            }
        }
    }

    public static function invalidInputs(): iterable
    {
        foreach ([null, 0, -1, 3, '01', '1.0', 1.0, true, ' 1 ', [], '+1', '1e0'] as $value) {
            yield ['process', $value];
        }
        foreach ([null, [], [1], [0, 0, 0], [-1, 0], [1.5, 0], [1.0, 0], [true, 0], [null, 0], ['01', 0], [' 1 ', 0], ['+1', 0], ['1e0', 0], [1000001, 0], [[0], 0], '{"0":0,"1":0}', 'bad', [0 => 0, 2 => 0]] as $value) {
            yield ['request', $value];
        }
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_inputs_are_rejected(string $field, mixed $value): void
    {
        $input = new ScenarioInput;
        $scenario = $input->validate(['n' => 2, 'm' => 2, 'allocation' => [[0, 0], [0, 0]], 'max' => [[1, 1], [1, 1]], 'available' => [1, 1]]);
        $data = ['process' => 1, 'request' => [0, 0]];
        $data[$field] = $value;
        $this->expectException(ValidationException::class);
        (new ResourceRequestInput($input))->validate($data, $scenario);
    }

    public function test_early_rejections_do_not_run_the_safety_analyzer(): void
    {
        $input = new ScenarioInput;
        $scenario = $input->validate(['n' => 1, 'm' => 1, 'allocation' => [[0]], 'max' => [[1]], 'available' => [0]]);
        $analyzer = $this->mock(BankerSafetyAnalyzer::class);
        $analyzer->shouldNotReceive('analyze');
        $evaluator = new ResourceRequestEvaluator(new NeedCalculator, $analyzer);
        $requests = new ResourceRequestInput($input);
        $this->assertSame('invalid', $evaluator->evaluate($scenario, $requests->validate(['process' => 1, 'request' => [2]], $scenario))->decision);
        $this->assertSame('wait', $evaluator->evaluate($scenario, $requests->validate(['process' => 1, 'request' => [1]], $scenario))->decision);
    }
}
