<?php

namespace App\Services\System;

use App\Models\ManagedProcess;
use RuntimeException;
use Throwable;

class ManagedProcessIdentityGuard
{
    public function __construct(private readonly TestProcessIdentityReader $reader) {}

    public function verify(ManagedProcess $process): array
    {
        if (! $process->exists || $process->getKey() === null || $process->pid <= 1
            || ! is_int($process->owner_uid) || $process->owner_uid < 0 || $process->start_time_ticks <= 0
            || $process->process_type !== 'sleep' || $process->command_label !== '/usr/bin/sleep 300') {
            return ['valid' => false, 'outcome' => 'invalid_record', 'message' => 'No se pudo validar la identidad del proceso.'];
        }

        try {
            $identity = $this->reader->read($process->pid);
        } catch (Throwable) {
            $exited = ! $this->reader->exists($process->pid)
                || $this->reader->hasExited($process->pid, $process->start_time_ticks);

            return $this->reject($process, $exited ? 'missing' : 'identity_mismatch');
        }

        if (! $this->matches($process, $identity)) {
            return $this->reject($process, 'identity_mismatch');
        }

        if (! in_array($process->status, ['running', 'stopped'], true)) {
            return ['valid' => false, 'outcome' => 'inactive', 'message' => 'El proceso de prueba ya no está activo.'];
        }

        return ['valid' => true, 'identity' => $identity];
    }

    public function matches(ManagedProcess $process, array $identity): bool
    {
        return ($identity['pid'] ?? null) === $process->pid
            && ($identity['owner_uid'] ?? null) === $process->owner_uid
            && ($identity['start_time_ticks'] ?? null) === $process->start_time_ticks;
    }

    public function updateStatus(ManagedProcess $process, string $status): void
    {
        $process->status = $status;
        if (! $process->save()) {
            throw new RuntimeException('No se pudo actualizar el registro del proceso de prueba.');
        }
    }

    public function reject(ManagedProcess $process, string $status): array
    {
        $this->updateStatus($process, $status);

        return ['valid' => false, 'outcome' => $status,
            'message' => $status === 'missing' ? 'El proceso ya no existe.' : 'No se pudo validar la identidad del proceso.'];
    }
}
