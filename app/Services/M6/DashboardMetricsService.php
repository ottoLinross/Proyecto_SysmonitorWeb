<?php

namespace App\Services\M6;

use App\Services\System\CpuInfoService;
use App\Services\System\CpuUsageService;
use App\Services\System\MemoryService;
use App\Services\System\ProcessService;
use Throwable;

class DashboardMetricsService
{
    public function __construct(
        private CpuInfoService $cpuInfo,
        private CpuUsageService $cpuUsage,
        private MemoryService $memory,
        private ProcessService $processes,
    ) {}

    /** @return array<string, array<string, int|float|string|null>> */
    public function read(): array
    {
        $cpuInfo = $this->readCpuInfo();
        $cpuUsage = $this->readCpuUsage();
        $memory = $this->readMemory();
        $processCount = $this->readProcessCount();

        $memoryUsed = $memory['memory_used_bytes'] ?? null;
        $memoryTotal = $memory['memory_total_bytes'] ?? null;
        $memoryPercent = is_int($memoryUsed) && is_int($memoryTotal) && $memoryTotal > 0
            ? round(($memoryUsed / $memoryTotal) * 100, 1)
            : null;

        return [
            'cpu' => [
                'usage_percent' => $cpuUsage,
                'model' => $cpuInfo['model'] ?? null,
                'logical_processors' => $cpuInfo['logical_processors'] ?? null,
                'load_average' => $cpuInfo['load_average'] ?? null,
                'uptime' => $cpuInfo['uptime_formatted'] ?? null,
            ],
            'memory' => [
                'used_bytes' => $memoryUsed,
                'total_bytes' => $memoryTotal,
                'usage_percent' => $memoryPercent,
            ],
            'processes' => ['count' => $processCount],
            'storage' => ['available' => null],
        ];
    }

    /** @return array<string, mixed> */
    private function readCpuInfo(): array
    {
        try {
            return $this->cpuInfo->getInfo();
        } catch (Throwable) {
            return [];
        }
    }

    private function readCpuUsage(): ?float
    {
        try {
            $usage = $this->cpuUsage->getUsagePercent();
        } catch (Throwable) {
            return null;
        }

        return (is_int($usage) || is_float($usage)) && is_finite((float) $usage) && $usage >= 0 && $usage <= 100
            ? (float) $usage
            : null;
    }

    /** @return array<string, int>|null */
    private function readMemory(): ?array
    {
        try {
            $memory = $this->memory->getInfo();
        } catch (Throwable) {
            return null;
        }

        if (! is_array($memory)
            || ! is_int($memory['memory_used_bytes'] ?? null)
            || ! is_int($memory['memory_total_bytes'] ?? null)
            || $memory['memory_used_bytes'] < 0
            || $memory['memory_total_bytes'] <= 0
            || $memory['memory_used_bytes'] > $memory['memory_total_bytes']) {
            return null;
        }

        return $memory;
    }

    private function readProcessCount(): ?int
    {
        try {
            return count($this->processes->getProcesses());
        } catch (Throwable) {
            return null;
        }
    }
}
