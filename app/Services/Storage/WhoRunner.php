<?php

namespace App\Services\Storage;

use Symfony\Component\Process\Process;

class WhoRunner
{
    public function run(): string
    {
        return StorageCommandOutput::run($this->createProcess());
    }

    protected function createProcess(): Process
    {
        return new Process(['/usr/bin/who'], null, ['LC_ALL' => 'C'], null, 5);
    }
}
