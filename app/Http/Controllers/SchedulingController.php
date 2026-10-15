<?php

namespace App\Http\Controllers;

use App\Services\Scheduling\ProcessInput;
use App\Services\Scheduling\RandomProcessGenerator;
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
}
