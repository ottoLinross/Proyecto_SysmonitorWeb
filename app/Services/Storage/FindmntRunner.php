<?php

namespace App\Services\Storage;

use Symfony\Component\Process\Process;

class FindmntRunner
{
    public function run(): string
    {
        return StorageCommandOutput::run($this->createProcess());
    }

    protected function createProcess(): Process
    {
        return new Process(['/usr/bin/findmnt', '--json', '--list', '--output', 'SOURCE,TARGET,FSTYPE'], null, null, null, 5);
    }
}
