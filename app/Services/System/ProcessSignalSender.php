<?php

namespace App\Services\System;

class ProcessSignalSender
{
    public function send(int $pid, int $signal): bool
    {
        if ($pid <= 1 || ! in_array($signal, [15, 9, 19, 18], true) || ! function_exists('posix_kill')) {
            return false;
        }

        return @posix_kill($pid, $signal);
    }
}
