<?php

namespace Tests\Unit;

use App\Services\Deadlock\BankerSafetyAnalyzer;
use App\Services\Deadlock\DeadlockScenario;
use App\Services\Deadlock\NeedCalculator;
use App\Services\Deadlock\RecoveryChoice;
use App\Services\Deadlock\RecoveryInput;
use App\Services\Deadlock\RecoverySimulator;
use App\Services\Deadlock\ScenarioInput;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeadlockRecoveryTest extends TestCase
{
    private function scenario(array $allocation, array $max, array $available): DeadlockScenario
    {
        return (new ScenarioInput)->validate(['n' => count($allocation), 'm' => count($available), 'allocation' => $allocation, 'max' => $max, 'available' => $available]);
    }

    private function simulator(): RecoverySimulator
    {
        return new RecoverySimulator(new BankerSafetyAnalyzer(new NeedCalculator));
    }

    public function test_termination_releases_resources_preserves_identity_and_reevaluates_only_survivors(): void
    {
        $scenario = $this->scenario([[1, 0], [0, 2], [1, 0]], [[2, 0], [0, 3], [2, 0]], [0, 0]);
        $before = serialize($scenario);
        $simulator = $this->simulator();
        $result = $simulator->simulate($scenario, new RecoveryChoice(RecoveryChoice::TERMINATE, 0));
        $this->assertSame([1, 0], $result->released);
        $this->assertSame([1, 2], $result->activeProcesses);
        $this->assertSame([[0, 2], [1, 0]], $result->simulated->allocation);
        $this->assertSame([[0, 3], [2, 0]], $result->simulated->max);
        $this->assertSame([1, 0], $result->simulated->available);
        $this->assertSame([[0, 1], [1, 0]], $result->safety->need);
        $this->assertFalse($result->safety->isSafe);
        $this->assertSame([0], $result->safety->unfinished);
        $this->assertSame([1], $result->safety->completed);
        $this->assertSame($before, serialize($scenario));
        $this->assertEquals($result, $simulator->simulate($scenario, new RecoveryChoice(RecoveryChoice::TERMINATE, 0)));
        for ($j = 0; $j < $scenario->m; $j++) {
            $this->assertSame($scenario->available[$j] + array_sum(array_column($scenario->allocation, $j)), $result->simulated->available[$j] + array_sum(array_column($result->simulated->allocation, $j)));
        }
    }

    public function test_release_can_make_the_remaining_state_safe(): void
    {
        $scenario = $this->scenario([[1], [1]], [[2], [2]], [0]);
        $result = $this->simulator()->simulate($scenario, new RecoveryChoice(RecoveryChoice::TERMINATE, 0));
        $this->assertTrue($result->safety->isSafe);
        $this->assertSame([1], $result->activeProcesses);
        $this->assertSame([0], $result->safety->safeSequence);
        $this->assertSame(1, $result->simulated->n);
    }

    public static function victims(): iterable
    {
        yield 'largest sum rather than first or largest individual cell' => [[[4, 0], [3, 3], [0, 5]], 1];
        yield 'tie chooses lowest original process' => [[[1, 2], [3, 0], [0, 0]], 0];
        yield 'all zero chooses first' => [[[0, 0], [0, 0]], 0];
    }

    #[DataProvider('victims')]
    public function test_automatic_victim_selection_is_deterministic(array $allocation, int $victim): void
    {
        $scenario = $this->scenario($allocation, $allocation, [0, 0]);
        $result = $this->simulator()->simulate($scenario, new RecoveryChoice(RecoveryChoice::VICTIM));
        $this->assertSame($victim, $result->victim);
        $this->assertSame($allocation[$victim], $result->released);
        $this->assertNotContains($victim, $result->activeProcesses);
    }

    public function test_retiring_last_process_produces_empty_safe_state_without_fabricated_steps(): void
    {
        $scenario = $this->scenario([[3, 0]], [[5, 2]], [1, 0]);
        $result = $this->simulator()->simulate($scenario, new RecoveryChoice(RecoveryChoice::TERMINATE, 0));
        $this->assertSame(0, $result->simulated->n);
        $this->assertSame([], $result->simulated->allocation);
        $this->assertSame([], $result->simulated->max);
        $this->assertSame([4, 0], $result->simulated->available);
        $this->assertSame([], $result->activeProcesses);
        $this->assertTrue($result->safety->isSafe);
        $this->assertSame([], $result->safety->safeSequence);
        $this->assertSame([], $result->safety->steps);
        $this->assertSame([], $result->safety->unfinished);
    }

    public function test_preemption_is_only_conceptual_and_does_not_run_the_analyzer(): void
    {
        $scenario = $this->scenario([[2]], [[3]], [0]);
        $before = serialize($scenario);
        $analyzer = $this->mock(BankerSafetyAnalyzer::class);
        $analyzer->shouldNotReceive('analyze');
        $result = (new RecoverySimulator($analyzer))->simulate($scenario, new RecoveryChoice(RecoveryChoice::PREEMPTION));
        $this->assertNull($result->victim);
        $this->assertSame([], $result->released);
        $this->assertNull($result->simulated);
        $this->assertNull($result->safety);
        $this->assertSame($before, serialize($scenario));
    }

    public function test_maximum_dimensions_values_and_zero_release(): void
    {
        $matrix = array_fill(0, 20, array_fill(0, 20, ScenarioInput::MAX_VALUE));
        $scenario = $this->scenario($matrix, $matrix, array_fill(0, 20, ScenarioInput::MAX_VALUE));
        $result = $this->simulator()->simulate($scenario, new RecoveryChoice(RecoveryChoice::TERMINATE, 19));
        $this->assertSame(range(0, 18), $result->activeProcesses);
        $this->assertSame(array_fill(0, 20, 2000000), $result->simulated->available);
        $this->assertTrue($result->safety->isSafe);
        $scenario = $this->scenario([[0], [0]], [[1], [1]], [0]);
        $result = $this->simulator()->simulate($scenario, new RecoveryChoice(RecoveryChoice::TERMINATE, 0));
        $this->assertSame([0], $result->released);
        $this->assertFalse($result->safety->isSafe);
    }

    public function test_input_reuses_canonical_process_validation(): void
    {
        $scenario = $this->scenario([[0]], [[0]], [0]);
        $input = new RecoveryInput(new ScenarioInput);
        $choice = $input->validate(['strategy' => 'terminate', 'recovery_process' => '1'], $scenario);
        $this->assertSame(0, $choice->process);
        $this->assertNull($input->validate(['strategy' => 'victim', 'recovery_process' => '<script>'], $scenario)->process);
        $this->expectException(ValidationException::class);
        $input->validate(['strategy' => 'terminate', 'recovery_process' => '01'], $scenario);
    }
}
