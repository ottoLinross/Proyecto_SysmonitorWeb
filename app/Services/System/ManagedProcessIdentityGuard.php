<?php

namespace App\Services\System;

use App\Models\ManagedProcess;
use RuntimeException;
use Throwable;

class ManagedProcessIdentityGuard
{
    public const TYPES = ['sleep' => '/usr/bin/sleep 300'];

    public const ACTIVE_STATUSES = ['running', 'stopped'];

    public const TERMINAL_STATUSES = ['terminated', 'killed', 'missing', 'identity_mismatch'];

    public function __construct(
        private readonly TestProcessIdentityReader $reader,
        private readonly ApplicationProcessUid $applicationUid,
        private readonly ManagedProcessProvenance $provenance,
    ) {}

    public function recognizesRecord(ManagedProcess $process): bool
    {
        $attributes = $process->getAttributes();
        if (! $process->exists || ! $this->integer($attributes['id'] ?? null, 1, PHP_INT_MAX)
            || ! $this->integer($attributes['pid'] ?? null, 2, 2147483647)
            || ! $this->integer($attributes['owner_uid'] ?? null, 0, 4294967295)
            || ! $this->integer($attributes['start_time_ticks'] ?? null, 1, PHP_INT_MAX)
            || ! is_string($process->process_type) || ! array_key_exists($process->process_type, self::TYPES)
            || $process->command_label !== self::TYPES[$process->process_type]
            || ! in_array($process->status, [...self::ACTIVE_STATUSES, ...self::TERMINAL_STATUSES], true)) {
            return false;
        }

        return $this->provenance->verify($process);
    }

    public function verify(ManagedProcess $process): array
    {
        if (! $this->recognizesRecord($process)) {
            if ($process->exists && $this->integer($process->getAttributes()['id'] ?? null, 1, PHP_INT_MAX)) {
                return $this->reject($process, 'identity_mismatch');
            }

            return ['valid' => false, 'outcome' => 'invalid_record', 'message' => 'No se pudo validar la identidad del proceso.'];
        }

        $uid = $this->applicationUid->effectiveUid();
        if ($uid === null || $uid !== $process->owner_uid) {
            return $this->reject($process, 'identity_mismatch');
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

        if (! in_array($process->status, self::ACTIVE_STATUSES, true)) {
            return ['valid' => false, 'outcome' => 'inactive', 'message' => 'El proceso de prueba ya no está activo.'];
        }

        return ['valid' => true, 'identity' => $identity];
    }

    public function canOfferActions(ManagedProcess $process): bool
    {
        return $this->recognizesRecord($process) && in_array($process->status, self::ACTIVE_STATUSES, true)
            && $this->applicationUid->effectiveUid() === $process->owner_uid;
    }

    public function matches(ManagedProcess $process, array $identity): bool
    {
        return ($identity['pid'] ?? null) === $process->pid
            && ($identity['owner_uid'] ?? null) === $process->owner_uid
            && ($identity['start_time_ticks'] ?? null) === $process->start_time_ticks;
    }

    private function integer(mixed $value, int $minimum, int $maximum): bool
    {
        if (! is_int($value) && (! is_string($value) || ! preg_match('/^(?:0|[1-9][0-9]*)$/D', $value))) {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_INT,
            ['options' => ['min_range' => $minimum, 'max_range' => $maximum]]) !== false;
    }

    public function updateStatus(ManagedProcess $process, string $status): void
    {
        $trusted = $this->provenance->verify($process);
        $process->status = $status;
        // Nunca otorgar procedencia a una fila que ya estaba manipulada o sin sello.
        if ($trusted) {
            $process->forceFill(['registration_signature' => $this->provenance->seal($process)]);
        }
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
