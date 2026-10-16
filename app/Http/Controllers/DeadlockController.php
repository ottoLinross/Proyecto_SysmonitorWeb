<?php

namespace App\Http\Controllers;

use App\Http\Requests\DeadlockScenarioRequest;
use App\Services\Deadlock\BankerSafetyAnalyzer;
use App\Services\Deadlock\ResourceRequestEvaluator;
use App\Services\Deadlock\ResourceRequestInput;
use App\Services\Deadlock\ScenarioInput;
use Illuminate\Http\Response;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
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

    public function evaluateRequest(DeadlockScenarioRequest $request, ScenarioInput $input, BankerSafetyAnalyzer $analyzer, ResourceRequestInput $requestInput, ResourceRequestEvaluator $evaluator): View|Response
    {
        $data = $request->scenarioInput();
        $scenario = $input->validate($data);
        $safety = $analyzer->analyze($scenario);
        $viewData = ['scenario' => $scenario, 'need' => $safety->need, 'safety' => $safety, 'requestValues' => $data];

        try {
            $resourceRequest = $requestInput->validate($data, $scenario);
        } catch (ValidationException $exception) {
            if ($request->expectsJson()) {
                throw $exception;
            }
            $viewData['errors'] = (new ViewErrorBag)->put('default', $exception->validator->errors());
            $viewData['requestInvalid'] = true;

            return response()->view('deadlocks.index', $viewData, 422);
        }

        $viewData['requestResult'] = $evaluator->evaluate($scenario, $resourceRequest);

        return view('deadlocks.index', $viewData);
    }
}
