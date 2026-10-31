<?php

namespace App\Http\Controllers\M6;

use App\Http\Controllers\Controller;
use App\Services\M6\DashboardMetricsService;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(DashboardMetricsService $metrics): View
    {
        return view('welcome', ['metrics' => $metrics->read()]);
    }
}
