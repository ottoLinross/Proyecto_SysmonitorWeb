<?php

namespace App\Services\System;

use Throwable;

class CpuUsageService
{
    private const STAT_PATH = '/proc/stat';

    private const SAMPLE_INTERVAL_MICROSECONDS = 100000;

    private const FIELDS = ['user', 'nice', 'system', 'idle', 'iowait', 'irq', 'softirq', 'steal'];

    public function getUsagePercent(): ?float
    {
        $previous = $this->parseSnapshot($this->readStat() ?? '');

        if ($previous === null) {
            return null;
        }

        $this->waitForSample();
        $current = $this->parseSnapshot($this->readStat() ?? '');

        return $current === null ? null : $this->calculateUsage($previous, $current);
    }

    /**
     * Solo la primera línea cpu global; guest y guest_nice no se suman de nuevo.
     *
     * @return array<string, int>|null
     */
    public function parseSnapshot(string $content): ?array
    {
        $line = explode("\n", $content, 2)[0];
        $columns = preg_split('/\s+/', trim($line));

        if ($columns === false || count($columns) < 9 || $columns[0] !== 'cpu') {
            return null;
        }

        $snapshot = [];

        foreach (self::FIELDS as $index => $field) {
            $value = $columns[$index + 1];

            if (! preg_match('/^[0-9]+$/D', $value)) {
                return null;
            }

            $counter = filter_var(ltrim($value, '0') ?: '0', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

            if ($counter === false) {
                return null;
            }

            $snapshot[$field] = $counter;
        }

        return $snapshot;
    }

    /**
     * @param  array<string, int>  $previous
     * @param  array<string, int>  $current
     */
    public function calculateUsage(array $previous, array $current): ?float
    {
        $busy = 0.0;
        $inactive = 0.0;

        foreach (self::FIELDS as $field) {
            $before = $previous[$field] ?? null;
            $after = $current[$field] ?? null;

            if (! is_int($before) || ! is_int($after) || $before < 0 || $after < $before) {
                return null;
            }

            // Restar enteros antes de acumular evita perder deltas pequeños en contadores grandes.
            $delta = $after - $before;

            if ($field === 'idle' || $field === 'iowait') {
                $inactive += $delta;
            } else {
                $busy += $delta;
            }
        }

        $total = $busy + $inactive;

        if (! is_finite($total) || $total <= 0.0) {
            return null;
        }

        $percentage = ($busy / $total) * 100;

        return is_finite($percentage) ? max(0.0, min(100.0, $percentage)) : null;
    }

    protected function readStat(): ?string
    {
        try {
            $content = @file_get_contents(self::STAT_PATH);
        } catch (Throwable) {
            return null;
        }

        return $content === false ? null : $content;
    }

    protected function waitForSample(): void
    {
        usleep(self::SAMPLE_INTERVAL_MICROSECONDS);
    }
}
