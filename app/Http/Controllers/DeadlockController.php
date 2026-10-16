<?php

namespace App\Http\Controllers;

use App\Http\Requests\DeadlockScenarioRequest;
use App\Services\Deadlock\NeedCalculator;
use App\Services\Deadlock\ScenarioInput;
use Illuminate\View\View;

class DeadlockController extends Controller
{
    public function index(): View
    {
        return view('deadlocks.index');
    }

    public function validateScenario(DeadlockScenarioRequest $request, ScenarioInput $input, NeedCalculator $calculator): View
    {
        $scenario = $input->validate($request->scenarioInput());

        return view('deadlocks.index', [
            'scenario' => $scenario,
            'need' => $calculator->calculate($scenario),
        ]);
    }
}
