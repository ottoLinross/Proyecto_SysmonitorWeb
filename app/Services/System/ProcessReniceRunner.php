<?php

namespace App\Services\System;

use Throwable;

class ProcessReniceRunner
{
    /** @return array{exit_code: int, stdout: string, stderr: string} */
    public function run(int $pid, int $nice): array
    {
        if ($pid <= 1 || $nice < -20 || $nice > 19) {
            return ['exit_code' => -1, 'stdout' => '', 'stderr' => ''];
        }

        return $this->execute(['/usr/bin/renice', '-n', (string) $nice, '-p', (string) $pid]);
    }

    protected function execute(array $command): array
    {
        $failure = ['exit_code' => -1, 'stdout' => '', 'stderr' => ''];
        if (! function_exists('proc_open')) {
            return $failure;
        }

        $stdout = null;
        $stderr = null;
        $process = null;
        $pipes = [];
        try {
            // Archivos temporales separados evitan bloquearse al drenar dos pipes.
            $stdout = @tmpfile();
            $stderr = @tmpfile();
            if (! is_resource($stdout) || ! is_resource($stderr)) {
                return $failure;
            }

            $process = @proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => $stdout, 2 => $stderr],
                $pipes, null, ['LC_ALL' => 'C']);
            if (! is_resource($process)) {
                return $failure;
            }
            $exitCode = proc_close($process);
            $process = null;
            rewind($stdout);
            rewind($stderr);

            return ['exit_code' => $exitCode, 'stdout' => stream_get_contents($stdout, 4096) ?: '',
                'stderr' => stream_get_contents($stderr, 4096) ?: ''];
        } catch (Throwable) {
            return $failure;
        } finally {
            if (is_resource($process)) {
                proc_close($process);
            }
            foreach ([$stdout, $stderr] as $stream) {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }
    }
}
