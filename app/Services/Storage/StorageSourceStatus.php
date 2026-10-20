<?php

namespace App\Services\Storage;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessStartFailedException;

class StorageSourceStatus
{
    public static function failure(\Throwable $exception): string
    {
        if ($exception instanceof ProcessStartFailedException
            || ($exception instanceof ProcessFailedException && in_array($exception->getProcess()->getExitCode(), [126, 127], true))) {
            return 'No disponible';
        }

        return 'Error de lectura';
    }
}
