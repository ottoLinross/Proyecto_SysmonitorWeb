<?php

namespace App\Http\Controllers;

use App\Http\Requests\DeadlockScenarioRequest;
use App\Services\Deadlock\BankerSafetyAnalyzer;
use App\Services\Deadlock\ScenarioInput;
use Illuminate\View\View;

class DeadlockController extends Controller
{
    public function index(): View
    {
        return view('deadlocks.index');
    }

    public function validateScenario(DeadlockScenarioRequest $request, ScenarioInput $input, BankerSafetyAnalyzer $analyzer): View
    {
        $scenario = $input->validate($request->scenarioInput());
        $safety = $analyzer->analyze($scenario);

        return view('deadlocks.index', [
            'scenario' => $scenario,
            'need' => $safety->need,
            'safety' => $safety,
        ]);
    }
}
