<?php

namespace App\Http\Controllers;

use App\Services\System\CpuInfoService;
use App\Services\System\CpuUsageService;
use App\Services\System\MemoryService;
use App\Services\System\ProcessRankingService;
use App\Services\System\ProcessService;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;
use Throwable;

class CpuMemoryController extends Controller
{
    public function metrics(
        CpuInfoService $cpuInfoService,
        CpuUsageService $cpuUsageService,
        MemoryService $memoryService,
        ProcessService $processService,
        ProcessRankingService $processRankingService,
    ): JsonResponse {
        try {
            $cpuInfo = $cpuInfoService->getInfo();
            $cpuUsage = $cpuUsageService->getUsagePercent();
            $memory = $memoryService->getInfo();
            $rankings = $processRankingService->getRankings($processService->getProcesses());

            // Lista explícita de campos públicos: nunca serializar los procesos completos.
            return response()->json([
                'status' => 'ok',
                'cpu' => [
                    'usage_percent' => $cpuUsage,
                    'model' => $cpuInfo['model'],
                    'logical_processors' => $cpuInfo['logical_processors'],
                ],
                'uptime' => [
                    'seconds' => $cpuInfo['uptime_seconds'],
                    'formatted' => $cpuInfo['uptime_formatted'],
                ],
                'load_average' => [
                    'one_minute' => $cpuInfo['load_average']['one_minute'] ?? null,
                    'five_minutes' => $cpuInfo['load_average']['five_minutes'] ?? null,
                    'fifteen_minutes' => $cpuInfo['load_average']['fifteen_minutes'] ?? null,
                ],
                'memory' => [
                    'total_bytes' => $memory['memory_total_bytes'] ?? null,
                    'used_bytes' => $memory['memory_used_bytes'] ?? null,
                    'free_bytes' => $memory['memory_free_bytes'] ?? null,
                    'cached_bytes' => $memory['memory_cached_bytes'] ?? null,
                ],
                'swap' => [
                    'total_bytes' => $memory['swap_total_bytes'] ?? null,
                    'used_bytes' => $memory['swap_used_bytes'] ?? null,
                    'free_bytes' => $memory['swap_free_bytes'] ?? null,
                ],
                'top_processes' => [
                    'cpu' => array_map(fn (array $process): array => [
                        'pid' => $process['pid'], 'cpu_percent' => $process['cpu_percent'],
                    ], $rankings['cpu']),
                    'memory' => array_map(fn (array $process): array => [
                        'pid' => $process['pid'], 'memory_percent' => $process['memory_percent'],
                    ], $rankings['memory']),
                ],
                'timestamp' => now()->utc()->toIso8601String(),
            ], 200, ['Cache-Control' => 'no-store'], JSON_PRESERVE_ZERO_FRACTION);
        } catch (Throwable) {
            return response()->json([
                'status' => 'error',
                'error' => [
                    'code' => 'metrics_unavailable',
                    'message' => 'No se pudieron obtener las métricas del sistema.',
                ],
                'timestamp' => now()->utc()->toIso8601String(),
            ], 503, ['Cache-Control' => 'no-store']);
        }
    }

    public function index(
        CpuInfoService $cpuInfoService,
        CpuUsageService $cpuUsageService,
        MemoryService $memoryService,
        ProcessService $processService,
        ProcessRankingService $processRankingService,
    ): View {
        $metricsUnavailable = false;
        $read = function (callable $operation, mixed $fallback) use (&$metricsUnavailable): mixed {
            try {
                return $operation();
            } catch (Throwable) {
                $metricsUnavailable = true;

                return $fallback;
            }
        };
        $cpuInfo = $read(fn () => $cpuInfoService->getInfo(), [
            'model' => null, 'logical_processors' => null, 'uptime_seconds' => null,
            'uptime_formatted' => null, 'load_average' => null,
        ]);
        $cpuUsage = $read(fn () => $cpuUsageService->getUsagePercent(), null);
        $memory = $read(fn () => $memoryService->getInfo(), null);
        $rankings = $read(fn () => $processRankingService->getRankings($processService->getProcesses()), ['cpu' => [], 'memory' => []]);

        return view('system.cpu-memory', [
            'cpuInfo' => $cpuInfo,
            'cpuUsagePercent' => $cpuUsage,
            'memoryInfo' => $memory,
            'metricsUnavailable' => $metricsUnavailable,
            'topCpuProcesses' => $rankings['cpu'],
            'topMemoryProcesses' => $rankings['memory'],
        ]);
    }
}
