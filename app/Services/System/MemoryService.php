<?php

namespace App\Services\System;

use Throwable;

class MemoryService
{
    private const MEMINFO_PATH = '/proc/meminfo';

    private const KEYS = ['MemTotal', 'MemFree', 'MemAvailable', 'Buffers', 'Cached', 'SReclaimable', 'SwapTotal', 'SwapFree'];

    /** @return array<string, int>|null */
    public function getInfo(): ?array
    {
        return $this->parseMemInfo($this->readMemInfo() ?? '');
    }

    /**
     * Valores internos en bytes: el kB de proc equivale a 1024 bytes.
     * Usada = total - MemAvailable. Sin MemAvailable, se estima disponible como
     * MemFree + Buffers + Cached + SReclaimable, limitado a MemTotal.
     * Libre representa MemFree; caché = Cached + SReclaimable (sin Buffers).
     * Libre, disponible y caché se limitan a su total para evitar negativos.
     *
     * @return array<string, int>|null
     */
    public function parseMemInfo(string $content): ?array
    {
        $values = [];

        foreach (explode("\n", $content) as $line) {
            $key = preg_split('/[:\s]/', trim($line), 2)[0];

            if (! in_array($key, self::KEYS, true)) {
                continue;
            }

            if (array_key_exists($key, $values)
                || ! preg_match('/^\s*[A-Za-z]+\s*:\s*([0-9]+)\s+kB\s*$/D', $line, $matches)) {
                return null;
            }

            $kilobytes = filter_var(ltrim($matches[1], '0') ?: '0', FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 0, 'max_range' => intdiv(PHP_INT_MAX, 1024)],
            ]);

            if ($kilobytes === false) {
                return null;
            }

            $values[$key] = $kilobytes * 1024;
        }

        foreach (['MemTotal', 'MemFree', 'SwapTotal', 'SwapFree'] as $required) {
            if (! array_key_exists($required, $values)) {
                return null;
            }
        }

        if ($values['MemTotal'] === 0) {
            return null;
        }

        $total = $values['MemTotal'];
        $free = min($values['MemFree'], $total);
        $cache = min($values['Cached'] ?? 0, $total);
        $cache += min($values['SReclaimable'] ?? 0, $total - $cache);

        if (isset($values['MemAvailable'])) {
            $available = min($values['MemAvailable'], $total);
        } else {
            // Acumular solo hasta el total también previene desbordamientos.
            $available = $free;
            foreach (['Buffers', 'Cached', 'SReclaimable'] as $key) {
                $available += min($values[$key] ?? 0, $total - $available);
            }
        }

        $swapTotal = $values['SwapTotal'];
        $swapFree = min($values['SwapFree'], $swapTotal);

        return [
            'memory_total_bytes' => $total,
            'memory_used_bytes' => $total - $available,
            'memory_free_bytes' => $free,
            'memory_cached_bytes' => $cache,
            'swap_total_bytes' => $swapTotal,
            'swap_used_bytes' => $swapTotal - $swapFree,
            'swap_free_bytes' => $swapFree,
        ];
    }

    protected function readMemInfo(): ?string
    {
        try {
            $content = @file_get_contents(self::MEMINFO_PATH);
        } catch (Throwable) {
            return null;
        }

        return $content === false ? null : $content;
    }
}
