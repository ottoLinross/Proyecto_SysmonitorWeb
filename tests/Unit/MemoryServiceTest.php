<?php

namespace Tests\Unit;

use App\Services\System\MemoryService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MemoryServiceTest extends TestCase
{
    private const BASE = "MemTotal: 1000 kB\nMemFree: 100 kB\nSwapTotal: 500 kB\nSwapFree: 200 kB\n";

    #[DataProvider('memoryCases')]
    public function test_memory_calculations_and_conversions(string $content, array $expectedKilobytes): void
    {
        $expected = array_combine([
            'memory_total_bytes', 'memory_used_bytes', 'memory_free_bytes', 'memory_cached_bytes',
            'swap_total_bytes', 'swap_used_bytes', 'swap_free_bytes',
        ], array_map(fn (int $value): int => $value * 1024, $expectedKilobytes));

        $actual = (new MemoryService)->parseMemInfo($content);

        $this->assertSame($expected, $actual);
        foreach ($actual as $value) {
            $this->assertIsInt($value);
            $this->assertGreaterThanOrEqual(0, $value);
        }
    }

    public static function memoryCases(): array
    {
        return [
            'normal with available and swap' => [self::BASE."MemAvailable: 600 kB\nBuffers: 50 kB\nCached: 200 kB\nSReclaimable: 50 kB", [1000, 400, 100, 250, 500, 300, 200]],
            'available preferred over fallback' => [self::BASE."MemAvailable: 800 kB\nCached: 200 kB", [1000, 200, 100, 200, 500, 300, 200]],
            'available zero is valid' => [self::BASE."MemAvailable: 0 kB\nCached: 200 kB", [1000, 1000, 100, 200, 500, 300, 200]],
            'fallback' => [self::BASE."Buffers: 50 kB\nCached: 200 kB\nSReclaimable: 50 kB", [1000, 600, 100, 250, 500, 300, 200]],
            'optional keys absent' => [self::BASE, [1000, 900, 100, 0, 500, 300, 200]],
            'only cached' => [self::BASE.'Cached: 200 kB', [1000, 700, 100, 200, 500, 300, 200]],
            'only reclaimable' => [self::BASE.'SReclaimable: 50 kB', [1000, 850, 100, 50, 500, 300, 200]],
            'swap disabled' => ["MemTotal: 1024 kB\nMemFree: 512 kB\nSwapTotal: 0 kB\nSwapFree: 0 kB", [1024, 512, 512, 0, 0, 0, 0]],
            'available over total' => [self::BASE.'MemAvailable: 2000 kB', [1000, 0, 100, 0, 500, 300, 200]],
            'fallback and cache over total' => [self::BASE."Cached: 2000 kB\nSReclaimable: 2000 kB", [1000, 0, 100, 1000, 500, 300, 200]],
            'free over totals' => ["MemTotal: 1000 kB\nMemFree: 2000 kB\nSwapTotal: 500 kB\nSwapFree: 1000 kB", [1000, 0, 1000, 0, 500, 0, 500]],
            'irrelevant keys ignored' => [self::BASE."HugePages_Total: 0\nSwapCached: 40 kB\nShmem: 10 kB", [1000, 900, 100, 0, 500, 300, 200]],
            'whitespace' => [" \tMemTotal: 1000 kB\r\nMemFree:\t100\tkB\nSwapTotal: 500 kB\nSwapFree: 200 kB\n", [1000, 900, 100, 0, 500, 300, 200]],
        ];
    }

    #[DataProvider('invalidCases')]
    public function test_invalid_meminfo_is_unavailable(string $content): void
    {
        $this->assertNull((new MemoryService)->parseMemInfo($content));
    }

    public static function invalidCases(): array
    {
        return [
            'empty' => [''],
            'whitespace' => ["\n \t"],
            'missing total' => ["MemFree: 100 kB\nSwapTotal: 0 kB\nSwapFree: 0 kB"],
            'missing free' => ["MemTotal: 1000 kB\nSwapTotal: 0 kB\nSwapFree: 0 kB"],
            'missing swap keys' => ["MemTotal: 1000 kB\nMemFree: 100 kB"],
            'zero total' => ["MemTotal: 0 kB\nMemFree: 0 kB\nSwapTotal: 0 kB\nSwapFree: 0 kB"],
            'negative' => [self::BASE.'Cached: -10 kB'],
            'negative available' => [self::BASE.'MemAvailable: -10 kB'],
            'text' => [self::BASE.'Cached: invalid kB'],
            'decimal' => [self::BASE.'Cached: 1.5 kB'],
            'wrong unit' => [self::BASE.'Cached: 10 MB'],
            'missing unit' => [self::BASE.'Cached: 10'],
            'duplicate' => [self::BASE.'MemFree: 100 kB'],
            'overflow' => [self::BASE.'Cached: '.PHP_INT_MAX.' kB'],
            'extra data' => [self::BASE.'Cached: 10 kB invalid'],
        ];
    }

    public function test_large_values_do_not_overflow_during_fallback(): void
    {
        $maximum = intdiv(PHP_INT_MAX, 1024);
        $content = "MemTotal: $maximum kB\nMemFree: $maximum kB\nCached: $maximum kB\nSReclaimable: $maximum kB\nSwapTotal: 0 kB\nSwapFree: 0 kB";
        $info = (new MemoryService)->parseMemInfo($content);

        $this->assertSame($maximum * 1024, $info['memory_total_bytes']);
        $this->assertSame($maximum * 1024, $info['memory_cached_bytes']);
        $this->assertSame(0, $info['memory_used_bytes']);
    }

    public function test_simulated_reading_is_parsed(): void
    {
        $service = $this->getMockBuilder(MemoryService::class)->onlyMethods(['readMemInfo'])->getMock();
        $service->expects($this->once())->method('readMemInfo')->willReturn(self::BASE);

        $this->assertSame((new MemoryService)->parseMemInfo(self::BASE), $service->getInfo());
    }

    public function test_read_failure_returns_unavailable(): void
    {
        $service = $this->getMockBuilder(MemoryService::class)->onlyMethods(['readMemInfo'])->getMock();
        $service->expects($this->once())->method('readMemInfo')->willReturn(null);

        $this->assertNull($service->getInfo());
    }
}
