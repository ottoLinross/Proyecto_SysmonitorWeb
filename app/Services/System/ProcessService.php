<?php

namespace App\Services\System;

class ProcessService
{
    /**
     * @return list<array{pid: int, ppid: int, user: string, state: string, nice: int, cpu_percent: float, memory_percent: float, memory_kb: int, command: string}>
     */
    public function getProcesses(): array
    {
        $output = $this->readProcessOutput();

        if ($output === null) {
            return [];
        }

        $processes = [];

        foreach (explode("\n", $output) as $line) {
            // Solo las primeras ocho columnas se separan; args conserva sus espacios.
            $columns = preg_split('/\s+/', trim($line), 9);

            if ($columns === false || count($columns) !== 9) {
                continue;
            }

            [$pid, $ppid, $user, $state, $nice, $cpu, $memory, $rss, $command] = $columns;

            $pid = filter_var($pid, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $ppid = filter_var($ppid, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            $nice = filter_var($nice, FILTER_VALIDATE_INT, ['options' => ['min_range' => -20, 'max_range' => 19]]);
            $rss = filter_var($rss, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

            if ($pid === false || $ppid === false || $nice === false || $rss === false
                || ! preg_match('/^[RSDTtXZxKWIP][<NLsl+]*$/', $state)
                || ! preg_match('/^\d+(?:\.\d+)?$/', $cpu)
                || ! preg_match('/^\d+(?:\.\d+)?$/', $memory)
                || ! is_finite((float) $cpu) || ! is_finite((float) $memory)
                || $command === '') {
                continue;
            }

            $processes[] = [
                'pid' => $pid,
                'ppid' => $ppid,
                'user' => $user,
                'state' => $state,
                'nice' => $nice,
                'cpu_percent' => (float) $cpu,
                'memory_percent' => (float) $memory,
                'memory_kb' => $rss,
                'command' => $command,
            ];
        }

        return $processes;
    }

    /**
     * Transforma una fotografía de procesos sin leer el sistema ni modificar la entrada.
     *
     * @return list<array>
     */
    public function buildProcessTree(array $processes): array
    {
        $nodes = [];

        foreach ($processes as $process) {
            $pid = filter_var($process['pid'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            if ($pid === false || isset($nodes[$pid])) {
                continue;
            }

            $nodes[$pid] = array_replace($process, ['children' => []]);
        }

        ksort($nodes, SORT_NUMERIC);
        $parents = [];

        foreach ($nodes as $pid => $node) {
            $ppid = filter_var($node['ppid'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            $parents[$pid] = $ppid !== false && $ppid !== 0 && $ppid !== $pid && isset($nodes[$ppid])
                ? $ppid
                : null;
        }

        // Recorrer enlaces de padres una sola vez y cortar todos los miembros de cada ciclo.
        $visited = [];

        foreach (array_keys($nodes) as $pid) {
            $path = [];
            $positions = [];
            $current = $pid;

            while ($current !== null && ! isset($visited[$current]) && ! isset($positions[$current])) {
                $positions[$current] = count($path);
                $path[] = $current;
                $current = $parents[$current];
            }

            if ($current !== null && isset($positions[$current])) {
                foreach (array_slice($path, $positions[$current]) as $cyclePid) {
                    $parents[$cyclePid] = null;
                }
            }

            foreach ($path as $pathPid) {
                $visited[$pathPid] = true;
            }
        }

        $tree = [];

        // Referencias sobre un grafo ya acíclico: cada nodo se incorpora exactamente una vez.
        foreach (array_keys($nodes) as $pid) {
            $parent = $parents[$pid];

            if ($parent === null) {
                $tree[] = &$nodes[$pid];
            } else {
                $nodes[$parent]['children'][] = &$nodes[$pid];
            }
        }

        return $tree;
    }

    /**
     * Un fallo de lectura devuelve null sin propagar la salida de error del sistema.
     */
    protected function readProcessOutput(): ?string
    {
        if (! function_exists('proc_open')) {
            return null;
        }

        $pipes = [];

        try {
            $process = @proc_open(
                ['/usr/bin/ps', '-e', '-ww', '-o', 'pid=,ppid=,user=,stat=,ni=,pcpu=,pmem=,rss=,args='],
                [
                    0 => ['file', '/dev/null', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['file', '/dev/null', 'w'],
                ],
                $pipes,
                null,
                ['LC_ALL' => 'C'],
            );
        } catch (\Throwable) {
            return null;
        }

        if (! is_resource($process)) {
            return null;
        }

        try {
            $output = @stream_get_contents($pipes[1]);
        } catch (\Throwable) {
            return null;
        } finally {
            fclose($pipes[1]);
            $exitCode = proc_close($process);
        }

        return $exitCode === 0 && $output !== false ? $output : null;
    }
}
