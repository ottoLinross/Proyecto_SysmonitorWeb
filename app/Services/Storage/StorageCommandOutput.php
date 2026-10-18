<?php

namespace App\Services\Storage;

use Symfony\Component\Process\Process;

class StorageCommandOutput
{
    public const MAX_BYTES = 1048576;

    public static function run(Process $process): string
    {
        $output = '';
        $bytes = 0;
        $exceeded = false;
        try {
            $process->mustRun(function (string $type, string $chunk) use ($process, &$output, &$bytes, &$exceeded): void {
                // Drain Symfony's buffers as well as limiting our own copy.
                $process->clearOutput();
                $process->clearErrorOutput();
                if ($exceeded) {
                    return;
                }
                $bytes += strlen($chunk);
                if ($bytes > self::MAX_BYTES) {
                    $exceeded = true;
                    throw new \RuntimeException('Storage output limit exceeded');
                }
                if ($type === Process::OUT) {
                    $output .= $chunk;
                }
            });

            return $output;
        } finally {
            if ($process->isRunning()) {
                $process->stop(0);
            }
            $process->clearOutput();
            $process->clearErrorOutput();
        }
    }
}
