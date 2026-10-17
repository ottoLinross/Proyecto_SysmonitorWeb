<?php

namespace App\Http\Controllers;

use App\Http\Requests\DeadlockScenarioRequest;
use App\Services\Deadlock\BankerSafetyAnalyzer;
use App\Services\Deadlock\RecoveryInput;
use App\Services\Deadlock\RecoverySimulator;
use App\Services\Deadlock\ResourceCycleDetector;
use App\Services\Deadlock\ResourceGraphBuilder;
use App\Services\Deadlock\ResourceRequestEvaluator;
use App\Services\Deadlock\ResourceRequestInput;
use App\Services\Deadlock\ScenarioInput;
use Illuminate\Http\Response;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class DeadlockRecoveryController extends Controller
{
    public function simulate(DeadlockScenarioRequest $request, ScenarioInput $input, BankerSafetyAnalyzer $analyzer, ResourceGraphBuilder $graphs, ResourceCycleDetector $cycles, ResourceRequestInput $requests, ResourceRequestEvaluator $evaluator, RecoveryInput $choices, RecoverySimulator $simulator): View|Response
    {
        $data = $request->scenarioInput();
        $scenario = $input->validate($data);
        $safety = $analyzer->analyze($scenario);
        $graph = $graphs->build($scenario);
        $viewData = ['scenario' => $scenario, 'need' => $safety->need, 'safety' => $safety, 'graph' => $graph, 'cycleResult' => $cycles->detect($graph), 'recoveryValues' => $data];

        try {
            // Carry explicit request context only when submitted; never infer it from Need.
            if (array_key_exists('request', $data) || array_key_exists('process', $data)) {
                $resourceRequest = $requests->validate($data, $scenario);
                $viewData['requestValues'] = $data;
                $viewData['requestResult'] = $evaluator->evaluate($scenario, $resourceRequest);
                $viewData['graph'] = $graphs->build($scenario, $resourceRequest);
                $viewData['cycleResult'] = $cycles->detect($viewData['graph']);
            }
            $choice = $choices->validate($data, $scenario);
            $viewData['recoveryResult'] = $simulator->simulate($scenario, $choice);
        } catch (ValidationException $exception) {
            if ($request->expectsJson()) {
                throw $exception;
            }
            $viewData['errors'] = (new ViewErrorBag)->put('default', $exception->validator->errors());

            return response()->view('deadlocks.index', $viewData, 422);
        }

        return view('deadlocks.index', $viewData);
    }
}
