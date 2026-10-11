<?php

namespace Tests\Unit;

use App\Services\System\CpuInfoService;
use App\Services\System\CpuUsageService;
use App\Services\System\MemoryService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ProcReadFailureTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    #[DataProvider('failedReads')]
    public function test_fixed_proc_readers_handle_failed_or_empty_files(string $service, string $method, string $path, string $mode): void
    {
        require __DIR__.'/../Support/ProcFileReadStub.php';
        $GLOBALS['proc_test_mode'] = $mode;

        $reader = new ReflectionMethod($service, $method);
        $result = $reader->invoke(new $service);

        $this->assertSame($mode === 'empty' ? '' : null, $result);
        $this->assertSame($path, $GLOBALS['proc_test_path']);
    }

    public static function failedReads(): array
    {
        $cases = [];
        foreach ([
            [CpuInfoService::class, 'readCpuInfo', '/proc/cpuinfo'],
            [CpuInfoService::class, 'readUptime', '/proc/uptime'],
            [CpuInfoService::class, 'readLoadAverage', '/proc/loadavg'],
            [CpuUsageService::class, 'readStat', '/proc/stat'],
            [MemoryService::class, 'readMemInfo', '/proc/meminfo'],
        ] as [$service, $method, $path]) {
            foreach (['missing', 'inaccessible', 'empty'] as $mode) {
                $cases[$method.' '.$mode] = [$service, $method, $path, $mode];
            }
        }

        return $cases;
    }
}
