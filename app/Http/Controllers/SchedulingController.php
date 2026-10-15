<?php

namespace App\Http\Controllers;

use App\Http\Requests\SchedulingComparisonRequest;
use App\Http\Requests\SchedulingSimulationRequest;
use App\Services\Scheduling\ProcessInput;
use App\Services\Scheduling\RandomProcessGenerator;
use App\Services\Scheduling\SchedulingComparison;
use App\Services\Scheduling\SchedulingRunStore;
use App\Services\Scheduling\SchedulingSimulation;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class SchedulingController extends Controller
{
    public function index(): View
    {
        return view('scheduling.index');
    }

    public function manual(Request $request, ProcessInput $input): View
    {
        return view('scheduling.index', ['processes' => $input->validate($request->all())]);
    }

    public function random(Request $request, ProcessInput $input, RandomProcessGenerator $generator): View
    {
        return view('scheduling.index', [
            'processes' => $generator->generate($input->validateCount($request->all())),
        ]);
    }

    public function simulate(SchedulingSimulationRequest $request, ProcessInput $input, SchedulingSimulation $simulation, SchedulingRunStore $store): View|Response
    {
        $processes = $input->validate($request->all());
        $options = $request->validated();

        $result = $simulation->run($processes, $options['algorithm'], isset($options['quantum']) ? (int) $options['quantum'] : null);

        return $this->savedResults([
            'processes' => $processes,
            'simulation' => $result,
        ], fn () => $store->saveSimulation($processes, $result));
    }

    public function compare(SchedulingComparisonRequest $request, ProcessInput $input, SchedulingComparison $comparison, SchedulingRunStore $store): View|Response
    {
        $processes = $input->validate($request->all());

        $result = $comparison->compare($processes, (int) $request->validated('quantum'));

        return $this->savedResults([
            'processes' => $processes,
            'comparison' => $result,
        ], fn () => $store->saveComparison($processes, $result));
    }

    private function savedResults(array $data, callable $save): View|Response
    {
        try {
            $data['savedRun'] = $save();
        } catch (QueryException) {
            $data['saveError'] = 'No se pudo guardar la simulación. Los resultados se muestran, pero no están guardados.';

            return response()->view('scheduling.index', $data, 503);
        }

        return view('scheduling.index', $data);
    }
}
