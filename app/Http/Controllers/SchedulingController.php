<?php

namespace App\Http\Controllers;

use App\Http\Requests\SchedulingComparisonRequest;
use App\Http\Requests\SchedulingSimulationRequest;
use App\Services\Scheduling\ProcessInput;
use App\Services\Scheduling\RandomProcessGenerator;
use App\Services\Scheduling\SchedulingComparison;
use App\Services\Scheduling\SchedulingSimulation;
use Illuminate\Http\Request;
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

    public function simulate(SchedulingSimulationRequest $request, ProcessInput $input, SchedulingSimulation $simulation): View
    {
        $processes = $input->validate($request->all());
        $options = $request->validated();

        return view('scheduling.index', [
            'processes' => $processes,
            'simulation' => $simulation->run($processes, $options['algorithm'], isset($options['quantum']) ? (int) $options['quantum'] : null),
        ]);
    }

    public function compare(SchedulingComparisonRequest $request, ProcessInput $input, SchedulingComparison $comparison): View
    {
        $processes = $input->validate($request->all());

        return view('scheduling.index', [
            'processes' => $processes,
            'comparison' => $comparison->compare($processes, (int) $request->validated('quantum')),
        ]);
    }
}
