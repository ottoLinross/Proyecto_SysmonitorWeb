<?php

namespace App\Services\System;

use Throwable;

class CpuInfoService
{
    private const CPUINFO_PATH = '/proc/cpuinfo';

    private const UPTIME_PATH = '/proc/uptime';

    private const LOADAVG_PATH = '/proc/loadavg';

    /**
     * @return array{model: ?string, logical_processors: ?int, uptime_seconds: ?float, uptime_formatted: ?string, load_average: ?array{one_minute: float, five_minutes: float, fifteen_minutes: float}}
     */
    public function getInfo(): array
    {
        $cpu = $this->parseCpuInfo($this->readCpuInfo() ?? '');
        $uptime = $this->parseUptime($this->readUptime() ?? '');

        return array_merge($cpu, $uptime, [
            'load_average' => $this->parseLoadAverage($this->readLoadAverage() ?? ''),
        ]);
    }

    /**
     * Solo los tres primeros campos representan las cargas solicitadas.
     *
     * @return array{one_minute: float, five_minutes: float, fifteen_minutes: float}|null
     */
    public function parseLoadAverage(string $content): ?array
    {
        $fields = preg_split('/\s+/', trim($content));

        if ($fields === false || count($fields) < 3) {
            return null;
        }

        $loads = [];
        foreach (['one_minute', 'five_minutes', 'fifteen_minutes'] as $index => $key) {
            $value = $fields[$index];

            if (! preg_match('/^[0-9]+(?:\.[0-9]+)?$/D', $value) || ! is_finite((float) $value)) {
                return null;
            }

            $loads[$key] = (float) $value;
        }

        return $loads;
    }

    /**
     * @return array{model: ?string, logical_processors: ?int}
     */
    public function parseCpuInfo(string $content): array
    {
        $model = null;
        $processors = [];

        foreach (explode("\n", $content) as $line) {
            $fields = explode(':', $line, 2);

            if (count($fields) !== 2) {
                continue;
            }

            [$key, $value] = array_map('trim', $fields);

            if ($key === 'model name' && $value !== '' && $model === null) {
                $model = $value;
            }

            if ($key === 'processor' && preg_match('/^[0-9]+$/D', $value)) {
                $processors[ltrim($value, '0') ?: '0'] = true;
            }
        }

        return ['model' => $model, 'logical_processors' => $processors === [] ? null : count($processors)];
    }

    /**
     * @return array{uptime_seconds: ?float, uptime_formatted: ?string}
     */
    public function parseUptime(string $content): array
    {
        $unavailable = ['uptime_seconds' => null, 'uptime_formatted' => null];

        // /proc/uptime contiene dos números: uptime e idle acumulado de las CPU.
        if (! preg_match('/^([0-9]+(?:\.[0-9]+)?)\s+([0-9]+(?:\.[0-9]+)?)$/D', trim($content), $matches)) {
            return $unavailable;
        }

        $seconds = (float) $matches[1];

        if (! is_finite($seconds) || $seconds >= PHP_INT_MAX || ! is_finite((float) $matches[2])) {
            return $unavailable;
        }

        // Se conservan decimales en el dato; la presentación usa segundos completos.
        $wholeSeconds = (int) floor($seconds);
        $days = intdiv($wholeSeconds, 86400);
        $dayLabel = $days === 1 ? 'día' : 'días';
        $hours = intdiv($wholeSeconds % 86400, 3600);
        $minutes = intdiv($wholeSeconds % 3600, 60);
        $remainingSeconds = $wholeSeconds % 60;

        return [
            'uptime_seconds' => $seconds,
            'uptime_formatted' => "$days $dayLabel, $hours h, $minutes min, $remainingSeconds s",
        ];
    }

    protected function readCpuInfo(): ?string
    {
        try {
            $content = @file_get_contents(self::CPUINFO_PATH);
        } catch (Throwable) {
            return null;
        }

        return $content === false ? null : $content;
    }

    protected function readUptime(): ?string
    {
        try {
            $content = @file_get_contents(self::UPTIME_PATH);
        } catch (Throwable) {
            return null;
        }

        return $content === false ? null : $content;
    }

    protected function readLoadAverage(): ?string
    {
        try {
            $content = @file_get_contents(self::LOADAVG_PATH);
        } catch (Throwable) {
            return null;
        }

        return $content === false ? null : $content;
    }
}
