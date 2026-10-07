<?php

namespace App\Http\Controllers;

use App\Services\System\ProcessService;
use Illuminate\View\View;

class ProcessController extends Controller
{
    public function index(ProcessService $processService): View
    {
        return view('processes.index', [
            'processes' => $processService->getProcesses(),
        ]);
    }
}
