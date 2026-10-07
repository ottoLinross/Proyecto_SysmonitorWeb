<?php

namespace App\Services\System;

use RuntimeException;
use Throwable;

class TestProcessLauncher
{
    public function launch(): string
    {
        $script = base_path('scripts/launch-test-process.sh');

        if (! function_exists('proc_open') || ! is_file($script) || ! is_readable($script)) {
            throw new RuntimeException('No se pudo lanzar el proceso de prueba.');
        }

        $pipes = [];
        $process = null;

        try {
            $process = @proc_open(
                ['/bin/sh', $script],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
                $pipes,
                null,
                ['LC_ALL' => 'C'],
            );

            if (! is_resource($process)) {
                throw new RuntimeException;
            }

            $output = @stream_get_contents($pipes[1], 64);
        } catch (Throwable) {
            throw new RuntimeException('No se pudo lanzar el proceso de prueba.');
        } finally {
            if (isset($pipes[1]) && is_resource($pipes[1])) {
                fclose($pipes[1]);
            }
            if (is_resource($process)) {
                $exitCode = proc_close($process);
            }
        }

        if ($exitCode !== 0 || $output === false) {
            throw new RuntimeException('No se pudo lanzar el proceso de prueba.');
        }

        return trim($output);
    }
}
