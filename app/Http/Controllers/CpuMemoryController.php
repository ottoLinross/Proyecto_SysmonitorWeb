<?php

namespace App\Http\Controllers;

use App\Services\System\CpuInfoService;
use App\Services\System\CpuUsageService;
use Illuminate\View\View;

class CpuMemoryController extends Controller
{
    public function index(CpuInfoService $cpuInfoService, CpuUsageService $cpuUsageService): View
    {
        return view('system.cpu-memory', [
            'cpuInfo' => $cpuInfoService->getInfo(),
            'cpuUsagePercent' => $cpuUsageService->getUsagePercent(),
        ]);
    }
}
