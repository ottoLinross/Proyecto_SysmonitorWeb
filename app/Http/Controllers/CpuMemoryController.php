<?php

namespace App\Http\Controllers;

use App\Services\System\CpuInfoService;
use App\Services\System\CpuUsageService;
use App\Services\System\MemoryService;
use App\Services\System\ProcessRankingService;
use App\Services\System\ProcessService;
use Illuminate\View\View;

class CpuMemoryController extends Controller
{
    public function index(
        CpuInfoService $cpuInfoService,
        CpuUsageService $cpuUsageService,
        MemoryService $memoryService,
        ProcessService $processService,
        ProcessRankingService $processRankingService,
    ): View {
        $rankings = $processRankingService->getRankings($processService->getProcesses());

        return view('system.cpu-memory', [
            'cpuInfo' => $cpuInfoService->getInfo(),
            'cpuUsagePercent' => $cpuUsageService->getUsagePercent(),
            'memoryInfo' => $memoryService->getInfo(),
            'topCpuProcesses' => $rankings['cpu'],
            'topMemoryProcesses' => $rankings['memory'],
        ]);
    }
}
