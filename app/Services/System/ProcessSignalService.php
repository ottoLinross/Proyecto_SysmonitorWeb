<?php

namespace App\Services\System;

use App\Models\ManagedProcess;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

class ProcessSignalService
{
    // Valores Linux fijos, disponibles incluso si PHP no tiene la extensión POSIX.
    public const SIGNALS = [
        'term' => ['number' => 15, 'label' => 'SIGTERM'],
        'kill' => ['number' => 9, 'label' => 'SIGKILL'],
        'stop' => ['number' => 19, 'label' => 'SIGSTOP'],
        'cont' => ['number' => 18, 'label' => 'SIGCONT'],
    ];

    public function __construct(
        private readonly TestProcessIdentityReader $identityReader,
        private readonly ProcessSignalSender $sender,
    ) {}

    /** @return array{managed_process_id: int, pid: int, signal: string, success: bool, outcome: string, message: string, occurred_at: string} */
    public function send(ManagedProcess $process, string $signal): array
    {
        if (! array_key_exists($signal, self::SIGNALS)) {
            return $this->result($process, $signal, false, 'invalid_signal', 'Señal no permitida.');
        }

        if (! $process->exists || $process->getKey() === null || $process->pid <= 1
            || $process->owner_uid < 0 || $process->start_time_ticks <= 0
            || $process->process_type !== 'sleep' || $process->command_label !== '/usr/bin/sleep 300') {
            return $this->result($process, $signal, false, 'invalid_record', 'No se pudo validar la identidad del proceso.');
        }

        try {
            $identity = $this->identityReader->read($process->pid);
        } catch (Throwable) {
            $exited = ! $this->identityReader->exists($process->pid)
                || $this->identityReader->hasExited($process->pid, $process->start_time_ticks);
            $status = $exited ? 'missing' : 'identity_mismatch';

            return $this->rejectIdentity($process, $signal, $status);
        }

        if (! $this->matches($process, $identity)) {
            return $this->rejectIdentity($process, $signal, 'identity_mismatch');
        }

        // Se revalida incluso un registro terminal, pero nunca se vuelve a actuar sobre él.
        if (! in_array($process->status, ['running', 'stopped'], true)) {
            return $this->result($process, $signal, false, 'inactive', 'El proceso de prueba ya no está activo.');
        }

        if (! $this->sender->send($process->pid, self::SIGNALS[$signal]['number'])) {
            return $this->result($process, $signal, false, 'send_failed', 'No se pudo enviar la señal al proceso de prueba.');
        }

        $label = self::SIGNALS[$signal]['label'];
        if ($signal === 'stop' || $signal === 'cont') {
            $this->updateStatus($process, $signal === 'stop' ? 'stopped' : 'running');

            return $this->result($process, $signal, true, 'sent', $label.' enviada correctamente al proceso de prueba.');
        }

        // Máximo 9 pausas de 20 ms. Un zombie ya ha finalizado aunque /proc espere al reaper.
        for ($attempt = 0; $attempt < 10; $attempt++) {
            if ($this->identityReader->hasExited($process->pid, $process->start_time_ticks)) {
                $this->updateStatus($process, $signal === 'term' ? 'terminated' : 'killed');

                return $this->result($process, $signal, true, 'confirmed', $label.' enviada correctamente al proceso de prueba.');
            }

            try {
                $identity = $this->identityReader->read($process->pid);
            } catch (Throwable) {
                if (! $this->identityReader->exists($process->pid)
                    || $this->identityReader->hasExited($process->pid, $process->start_time_ticks)) {
                    $this->updateStatus($process, $signal === 'term' ? 'terminated' : 'killed');

                    return $this->result($process, $signal, true, 'confirmed', $label.' enviada correctamente al proceso de prueba.');
                }

                return $this->rejectIdentity($process, $signal, 'identity_mismatch');
            }

            if (! $this->matches($process, $identity)) {
                return $this->rejectIdentity($process, $signal, 'identity_mismatch');
            }

            if ($attempt < 9) {
                $this->pause();
            }
        }

        return $this->result($process, $signal, true, 'pending', $label.' enviada; la finalización aún no se ha confirmado.');
    }

    protected function pause(): void
    {
        usleep(20000);
    }

    private function matches(ManagedProcess $process, array $identity): bool
    {
        return ($identity['pid'] ?? null) === $process->pid
            && ($identity['owner_uid'] ?? null) === $process->owner_uid
            && ($identity['start_time_ticks'] ?? null) === $process->start_time_ticks;
    }

    private function updateStatus(ManagedProcess $process, string $status): void
    {
        $process->status = $status;
        if (! $process->save()) {
            throw new RuntimeException('No se pudo actualizar el registro del proceso de prueba.');
        }
    }

    private function rejectIdentity(ManagedProcess $process, string $signal, string $status): array
    {
        $this->updateStatus($process, $status);

        return $this->result($process, $signal, false, $status,
            $status === 'missing' ? 'El proceso ya no existe.' : 'No se pudo validar la identidad del proceso.');
    }

    private function result(ManagedProcess $process, string $signal, bool $success, string $outcome, string $message): array
    {
        return [
            'managed_process_id' => (int) $process->getKey(),
            'pid' => (int) $process->pid,
            'signal' => $signal,
            'success' => $success,
            'outcome' => $outcome,
            'message' => $message,
            'occurred_at' => (new DateTimeImmutable)->format(DATE_ATOM),
        ];
    }
}
