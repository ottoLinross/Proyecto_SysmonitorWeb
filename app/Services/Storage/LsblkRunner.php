<?php

namespace App\Services\Storage;

use Symfony\Component\Process\Process;

class LsblkRunner
{
    public function run(): string
    {
        $process = $this->createProcess();
        $process->mustRun();

        return $process->getOutput();
    }

    protected function createProcess(): Process
    {
        return new Process([
            '/usr/bin/lsblk', '-J', '-b', '-o',
            'NAME,PATH,TYPE,SIZE,FSTYPE,MOUNTPOINTS,PTTYPE,MODEL',
        ], null, null, null, 5);
    }
}
