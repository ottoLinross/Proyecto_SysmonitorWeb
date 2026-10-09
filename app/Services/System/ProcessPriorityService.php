<?php

namespace App\Services\System;

use App\Models\ManagedProcess;
use DateTimeImmutable;
use Throwable;

class ProcessPriorityService
{
    public function __construct(
        private readonly ManagedProcessIdentityGuard $guard,
        private readonly TestProcessIdentityReader $reader,
        private readonly ProcessReniceRunner $runner,
    ) {}

    public static function normalizeNice(mixed $value): ?int
    {
        if (! is_int($value) && (! is_string($value) || ! preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $value))) {
            return null;
        }
        $nice = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => -20, 'max_range' => 19]]);

        return $nice === false ? null : $nice;
    }

    public function change(ManagedProcess $process, mixed $value): array
    {
        $nice = self::normalizeNice($value);
        if ($nice === null) {
            return $this->result($process, null, false, 'invalid_nice', 'El valor nice debe estar entre -20 y 19.');
        }

        // Ninguna operación entre la validación de identidad y el runner.
        $check = $this->guard->verify($process);
        if (! $check['valid']) {
            return $this->result($process, $nice, false, $check['outcome'], $check['message']);
        }

        try {
            $execution = $this->runner->run($process->pid, $nice);
        } catch (Throwable) {
            return $this->failure($process, $nice);
        }
        if (($execution['exit_code'] ?? null) !== 0) {
            return $this->failure($process, $nice);
        }

        // Se exige una nueva fotografía consistente: identidad y nice del mismo proceso.
        try {
            $current = $this->reader->readWithNice($process->pid);
        } catch (Throwable) {
            $exited = ! $this->reader->exists($process->pid)
                || $this->reader->hasExited($process->pid, $process->start_time_ticks);
            $check = $this->guard->reject($process, $exited ? 'missing' : 'identity_mismatch');

            return $this->result($process, $nice, false, $check['outcome'], $check['message']);
        }
        if (! $this->guard->matches($process, $current)) {
            $check = $this->guard->reject($process, 'identity_mismatch');

            return $this->result($process, $nice, false, $check['outcome'], $check['message']);
        }
        if (($current['nice'] ?? null) !== $nice) {
            return $this->failure($process, $nice);
        }

        return $this->result($process, $nice, true, 'confirmed', 'Prioridad actualizada correctamente.');
    }

    private function failure(ManagedProcess $process, int $nice): array
    {
        return $this->result($process, $nice, false, 'change_failed', 'No fue posible cambiar la prioridad del proceso.');
    }

    private function result(ManagedProcess $process, ?int $nice, bool $success, string $outcome, string $message): array
    {
        return ['managed_process_id' => (int) $process->getKey(), 'pid' => (int) $process->pid,
            'nice' => $nice, 'success' => $success, 'outcome' => $outcome, 'message' => $message,
            'occurred_at' => (new DateTimeImmutable)->format(DATE_ATOM)];
    }
}
