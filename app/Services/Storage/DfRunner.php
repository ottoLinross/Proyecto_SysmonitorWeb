<?php

namespace App\Services\Storage;

use Symfony\Component\Process\Process;

class DfRunner
{
    public function run(): string
    {
        return StorageCommandOutput::run($this->createProcess());
    }

    protected function createProcess(): Process
    {
        return new Process(['/usr/bin/df', '-hP'], null, ['LC_ALL' => 'C'], null, 5);
    }
}
