<?php

namespace App\Services\System;

// Se carga exclusivamente en procesos PHPUnit aislados; no modifica /proc.
function file_get_contents(string $path): string|false
{
    $GLOBALS['proc_test_path'] = $path;

    return match ($GLOBALS['proc_test_mode']) {
        'empty' => '',
        'missing' => false,
        'inaccessible' => throw new \RuntimeException('read failure private-marker'),
    };
}
