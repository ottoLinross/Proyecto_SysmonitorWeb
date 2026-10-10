<?php

namespace App\Services\System;

use Throwable;

class CpuInfoService
{
    private const CPUINFO_PATH = '/proc/cpuinfo';

    private const UPTIME_PATH = '/proc/uptime';

    /**
     * @return array{model: ?string, logical_processors: ?int, uptime_seconds: ?float, uptime_formatted: ?string}
     */
    public function getInfo(): array
    {
        $cpu = $this->parseCpuInfo($this->readCpuInfo() ?? '');
        $uptime = $this->parseUptime($this->readUptime() ?? '');

        return array_merge($cpu, $uptime);
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
}
