<?php

namespace App\Http\Controllers;

use App\Services\System\CpuInfoService;
use Illuminate\View\View;

class CpuMemoryController extends Controller
{
    public function index(CpuInfoService $cpuInfoService): View
    {
        return view('system.cpu-memory', ['cpuInfo' => $cpuInfoService->getInfo()]);
    }
}
