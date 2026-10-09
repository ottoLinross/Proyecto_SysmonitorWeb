<?php

namespace App\Services\System;

use App\Models\ManagedProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class TestProcessService
{
    public function __construct(
        private readonly TestProcessLauncher $launcher,
        private readonly TestProcessIdentityReader $identityReader,
        private readonly ManagedProcessProvenance $provenance,
    ) {}

    public function launch(): ManagedProcess
    {
        try {
            // No lanzar un sleep si el registro todavía no está preparado.
            if (! Schema::hasTable('managed_processes') || ! Schema::hasColumn('managed_processes', 'registration_signature')
                || ! $this->provenance->available()) {
                throw new RuntimeException;
            }

            $output = $this->launcher->launch();
            $pid = preg_match('/^[1-9][0-9]*$/', $output)
                ? filter_var($output, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]])
                : false;

            if ($pid === false) {
                throw new RuntimeException;
            }

            $identity = $this->identityReader->read($pid);

            if (($identity['pid'] ?? null) !== $pid
                || ! is_int($identity['owner_uid'] ?? null) || $identity['owner_uid'] < 0
                || ! is_int($identity['start_time_ticks'] ?? null) || $identity['start_time_ticks'] <= 0) {
                throw new RuntimeException;
            }

            return DB::transaction(function () use ($pid, $identity): ManagedProcess {
                $process = ManagedProcess::create([
                    'pid' => $pid,
                    'process_type' => 'sleep',
                    'command_label' => '/usr/bin/sleep 300',
                    'owner_uid' => $identity['owner_uid'],
                    'start_time_ticks' => $identity['start_time_ticks'],
                    'status' => 'running',
                    'launched_at' => now(),
                ]);
                $process->forceFill(['registration_signature' => $this->provenance->seal($process)]);
                if (! $process->save()) {
                    throw new RuntimeException;
                }

                return $process;
            });
        } catch (Throwable) {
            throw new RuntimeException('No se pudo crear el proceso de prueba.');
        }
    }
}
