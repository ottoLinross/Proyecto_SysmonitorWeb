<?php

namespace App\Services\Storage;

class SystemUsersReader
{
    public function passwd(): string
    {
        return $this->read('/etc/passwd');
    }

    public function loginDefs(): string
    {
        return $this->read('/etc/login.defs');
    }

    private function read(string $path): string
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException('System users file unavailable');
        }

        return $contents;
    }
}
