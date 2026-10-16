<?php

namespace Tests\Unit;

use App\Services\Deadlock\ScenarioInput;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class DeadlockScenarioInputTest extends TestCase
{
    private function valid(): array
    {
        return ['n' => 2, 'm' => 3, 'allocation' => [[3, 1, 2], [0, 2, 1]], 'max' => [[5, 2, 4], [1, 3, 2]], 'available' => [7, 0, 4]];
    }

    public function test_valid_scenario_preserves_order_and_normalizes_integers(): void
    {
        $data = $this->valid();
        $data['n'] = '2';
        $data['allocation'][0][1] = '1';
        $result = (new ScenarioInput)->validate($data);
        $this->assertSame(2, $result->n);
        $this->assertSame(3, $result->m);
        $this->assertSame([[3, 1, 2], [0, 2, 1]], $result->allocation);
        $this->assertSame($data['max'], $result->max);
        $this->assertSame([7, 0, 4], $result->available);
    }

    public static function invalidScenarios(): iterable
    {
        yield 'empty allocation' => ['allocation', [], 'allocation'];
        yield 'empty max' => ['max', [], 'max'];
        yield 'irregular allocation' => ['allocation', [[0, 0, 0], [0]], 'allocation.1'];
        yield 'incorrect columns' => ['max', [[4, 4], [4, 4]], 'max.0'];
        yield 'incorrect rows' => ['allocation', [[0, 0, 0]], 'allocation'];
        yield 'empty row' => ['allocation', [[], [0, 0, 0]], 'allocation.0'];
        yield 'available short' => ['available', [0, 0], 'available'];
        yield 'available long' => ['available', [0, 0, 0, 0], 'available'];
        yield 'available empty' => ['available', [], 'available'];
        yield 'negative' => ['available', [-1, 0, 0], 'available.0'];
        yield 'float' => ['available', [1.5, 0, 0], 'available.0'];
        yield 'integral float' => ['available', [1.0, 0, 0], 'available.0'];
        yield 'boolean' => ['available', [true, 0, 0], 'available.0'];
        yield 'null' => ['available', [null, 0, 0], 'available.0'];
        yield 'decimal string' => ['available', ['1.0', 0, 0], 'available.0'];
        yield 'exponent' => ['available', ['1e2', 0, 0], 'available.0'];
        yield 'leading zero' => ['available', ['01', 0, 0], 'available.0'];
        yield 'plus' => ['available', ['+1', 0, 0], 'available.0'];
        yield 'whitespace' => ['available', [' 1 ', 0, 0], 'available.0'];
        yield 'overflow' => ['available', ['9999999999999999999999999999', 0, 0], 'available.0'];
        yield 'value limit' => ['available', [1000001, 0, 0], 'available.0'];
        yield 'allocation exceeds max' => ['allocation', [[6, 1, 2], [0, 2, 1]], 'allocation.0.0'];
        yield 'n zero' => ['n', 0, 'n'];
        yield 'n too large' => ['n', 21, 'n'];
        yield 'n negative' => ['n', -1, 'n'];
        yield 'n decimal' => ['n', 2.5, 'n'];
        yield 'n string ambiguous' => ['n', '02', 'n'];
        yield 'n missing' => ['n', null, 'n'];
        yield 'm zero' => ['m', 0, 'm'];
        yield 'm too large' => ['m', 21, 'm'];
        yield 'm negative' => ['m', -1, 'm'];
        yield 'm decimal' => ['m', '3.0', 'm'];
        yield 'm missing' => ['m', null, 'm'];
        yield 'associative rows' => ['allocation', ['P1' => [0, 0, 0], 'P2' => [0, 0, 0]], 'allocation'];
        yield 'sparse columns' => ['available', [0 => 1, 2 => 1, 3 => 1], 'available'];
        yield 'json object' => ['available', '{"0":1,"1":2,"2":3}', 'available'];
        yield 'invalid json' => ['allocation', '[[1,]]', 'allocation'];
        yield 'nested cell' => ['available', [[1], 0, 0], 'available.0'];
    }

    #[DataProvider('invalidScenarios')]
    public function test_invalid_scenarios_are_rejected(string $field, mixed $value, string $error): void
    {
        $data = $this->valid();
        $data[$field] = $value;
        try {
            (new ScenarioInput)->validate($data);
            $this->fail('Expected validation failure.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($error, $exception->errors());
        }
    }

    public function test_minimum_and_maximum_dimensions_and_values(): void
    {
        foreach ([1, 20] as $size) {
            $matrix = array_fill(0, $size, array_fill(0, $size, ScenarioInput::MAX_VALUE));
            $result = (new ScenarioInput)->validate(['n' => $size, 'm' => $size, 'allocation' => $matrix, 'max' => $matrix, 'available' => array_fill(0, $size, 0)]);
            $this->assertSame($matrix, $result->allocation);
            $this->assertCount($size, $result->available);
        }
    }

    public function test_excessive_payload_is_rejected_before_parsing(): void
    {
        $this->expectException(HttpException::class);
        (new ScenarioInput)->validate(['allocation' => str_repeat('x', ScenarioInput::MAX_PAYLOAD_BYTES + 1)]);
    }
}
