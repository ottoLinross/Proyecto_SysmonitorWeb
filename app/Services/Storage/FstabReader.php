<?php

namespace App\Services\Storage;

class FstabReader
{
    public function read(): string
    {
        $contents = @file_get_contents('/etc/fstab');
        if ($contents === false) {
            throw new \RuntimeException('Fstab unavailable');
        }

        return $contents;
    }
}
