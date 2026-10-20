<?php

namespace App\Services\Storage;

class FstabReader
{
    public function read(): string
    {
        return StorageFileRead::read('/etc/fstab');
    }
}
