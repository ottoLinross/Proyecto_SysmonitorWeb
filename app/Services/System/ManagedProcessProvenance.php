<?php

namespace App\Services\System;

use App\Models\ManagedProcess;
use RuntimeException;

class ManagedProcessProvenance
{
    public function __construct(private readonly ?string $key = null) {}

    public function available(): bool
    {
        return $this->signingKey() !== '';
    }

    public function seal(ManagedProcess $process): string
    {
        $key = $this->signingKey();
        if ($key === '') {
            throw new RuntimeException('No se pudo verificar el registro del proceso.');
        }

        $attributes = $process->getAttributes();
        $payload = [];
        foreach (['id', 'pid', 'process_type', 'command_label', 'owner_uid', 'start_time_ticks', 'status', 'launched_at'] as $field) {
            $payload[$field] = (string) ($attributes[$field] ?? '');
        }

        return hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), $key);
    }

    public function verify(ManagedProcess $process): bool
    {
        $seal = $process->getAttributes()['registration_signature'] ?? null;

        return $this->available() && is_string($seal) && preg_match('/^[a-f0-9]{64}$/D', $seal)
            && hash_equals($this->seal($process), $seal);
    }

    private function signingKey(): string
    {
        $key = $this->key ?? config('app.key');

        return is_string($key) ? $key : '';
    }
}
