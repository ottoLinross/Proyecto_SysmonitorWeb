<?php

namespace App\Services\Storage;

use Symfony\Component\Process\Process;

class FindmntRunner
{
    public function run(): string
    {
        $process = $this->createProcess();
        $process->mustRun();

        return $process->getOutput();
    }

    protected function createProcess(): Process
    {
        return new Process(['/usr/bin/findmnt', '--json', '--list', '--output', 'SOURCE,TARGET,FSTYPE'], null, null, null, 5);
    }
}
