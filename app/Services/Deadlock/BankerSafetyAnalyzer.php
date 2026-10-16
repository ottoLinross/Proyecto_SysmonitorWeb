<?php

namespace App\Services\Deadlock;

class BankerSafetyAnalyzer
{
    public function __construct(private NeedCalculator $calculator) {}

    /** Analyze a scenario already validated by ScenarioInput, without changing it. */
    public function analyze(DeadlockScenario $scenario): SafetyResult
    {
        $need = $this->calculator->calculate($scenario);
        $work = $scenario->available;
        $finish = array_fill(0, $scenario->n, false);
        $completed = [];

        while (count($completed) < $scenario->n) {
            $selected = null;
            // Restart at the first position after each completion for deterministic selection.
            foreach ($finish as $i => $finished) {
                if ($finished) {
                    continue;
                }
                foreach ($need[$i] as $j => $required) {
                    if ($required > $work[$j]) {
                        continue 2;
                    }
                }
                $selected = $i;
                break;
            }

            if ($selected === null) {
                break;
            }

            foreach ($scenario->allocation[$selected] as $j => $allocated) {
                $work[$j] += $allocated;
            }
            $finish[$selected] = true;
            $completed[] = $selected;
        }

        $unfinished = [];
        foreach ($finish as $i => $finished) {
            if (! $finished) {
                $unfinished[] = $i;
            }
        }
        $isSafe = $unfinished === [];

        return new SafetyResult($need, $isSafe, $isSafe ? $completed : null, $completed, $unfinished, $work, $finish);
    }
}
