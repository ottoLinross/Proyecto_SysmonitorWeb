<?php

namespace Tests\Unit;

use App\Services\System\CpuInfoService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CpuInfoServiceTest extends TestCase
{
    #[DataProvider('cpuInfoCases')]
    public function test_it_parses_cpu_info(string $content, ?string $model, ?int $processors): void
    {
        $this->assertSame(['model' => $model, 'logical_processors' => $processors], (new CpuInfoService)->parseCpuInfo($content));
    }

    public static function cpuInfoCases(): array
    {
        return [
            'valid model and processor' => ["processor\t: 0\nmodel name\t: Example CPU @ 2.40GHz\n", 'Example CPU @ 2.40GHz', 1],
            'multiple processors' => ["processor: 0\nmodel name: Example CPU\n\nprocessor: 1\nmodel name: Example CPU\nprocessor: 2", 'Example CPU', 3],
            'duplicates are not extra cpus' => ["processor: 0\nprocessor: 00\nprocessor: 2", null, 2],
            'first nonempty model preserves colons' => ["model name: \nmodel name: CPU: Example\nmodel name: Other", 'CPU: Example', null],
            'missing model' => ["processor: 0\ncpu cores: 8", null, 1],
            'missing processor' => ['model name: Example CPU', 'Example CPU', null],
            'invalid processor entries' => ["processor: -1\nprocessor: x\nprocessor: 1.5\nmodel name:", null, null],
            'incomplete' => ["processor\nmodel name\ncpu cores: 4", null, null],
            'empty' => ['', null, null],
            'whitespace' => [" \n\t", null, null],
        ];
    }

    #[DataProvider('uptimeCases')]
    public function test_it_parses_and_formats_uptime(string $content, ?float $seconds, ?string $formatted): void
    {
        $this->assertSame(['uptime_seconds' => $seconds, 'uptime_formatted' => $formatted], (new CpuInfoService)->parseUptime($content));
    }

    public static function uptimeCases(): array
    {
        return [
            'valid' => ['90061 100000', 90061.0, '1 día, 1 h, 1 min, 1 s'],
            'decimals' => [" 3661.99 8000.42\n", 3661.99, '0 días, 1 h, 1 min, 1 s'],
            'zero' => ['0.00 0.00', 0.0, '0 días, 0 h, 0 min, 0 s'],
            'subsecond' => ['0.99 2.45', 0.99, '0 días, 0 h, 0 min, 0 s'],
            'minute boundary' => ['60 70', 60.0, '0 días, 0 h, 1 min, 0 s'],
            'negative' => ['-1 2', null, null],
            'text' => ['invalid 2', null, null],
            'missing idle' => ['123.45', null, null],
            'invalid idle' => ['123.45 invalid', null, null],
            'negative idle' => ['123 -1', null, null],
            'extra data' => ['123 456 unexpected', null, null],
            'exponent' => ['1e3 2000', null, null],
            'comma' => ['1,5 2', null, null],
            'overflow' => [str_repeat('9', 400).' 0', null, null],
            'integer overflow' => [(string) PHP_INT_MAX.' 0', null, null],
            'idle overflow' => ['0 '.str_repeat('9', 400), null, null],
            'empty' => ['', null, null],
            'whitespace' => ["\n \t", null, null],
        ];
    }

    public function test_read_failures_return_unavailable_values(): void
    {
        $service = $this->getMockBuilder(CpuInfoService::class)->onlyMethods(['readCpuInfo', 'readUptime'])->getMock();
        $service->expects($this->once())->method('readCpuInfo')->willReturn(null);
        $service->expects($this->once())->method('readUptime')->willReturn(null);

        $this->assertSame([
            'model' => null, 'logical_processors' => null,
            'uptime_seconds' => null, 'uptime_formatted' => null,
        ], $service->getInfo());
    }

    public function test_get_info_combines_simulated_readings(): void
    {
        $service = $this->getMockBuilder(CpuInfoService::class)->onlyMethods(['readCpuInfo', 'readUptime'])->getMock();
        $service->expects($this->once())->method('readCpuInfo')->willReturn("processor: 0\nmodel name: Example CPU");
        $service->expects($this->once())->method('readUptime')->willReturn('60.25 120.50');

        $this->assertSame([
            'model' => 'Example CPU', 'logical_processors' => 1,
            'uptime_seconds' => 60.25, 'uptime_formatted' => '0 días, 0 h, 1 min, 0 s',
        ], $service->getInfo());
    }
}
