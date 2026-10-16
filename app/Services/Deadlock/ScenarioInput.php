<?php

namespace App\Services\Deadlock;

use Illuminate\Validation\ValidationException;
use JsonException;

class ScenarioInput
{
    public const MAX_PROCESSES = 20;

    public const MAX_RESOURCES = 20;

    public const MAX_VALUE = 1000000;

    public const MAX_PAYLOAD_BYTES = 32768;

    public function validate(array $input): DeadlockScenario
    {
        $this->checkPayloadSize($input);

        $n = $this->integer($input['n'] ?? null, 'n', 1, self::MAX_PROCESSES);
        $m = $this->integer($input['m'] ?? null, 'm', 1, self::MAX_RESOURCES);
        $allocation = $this->matrix($input['allocation'] ?? null, 'allocation', $n, $m);
        $max = $this->matrix($input['max'] ?? null, 'max', $n, $m);
        $available = $this->vector($this->decode($input['available'] ?? null, 'available'), 'available', $m);

        foreach ($allocation as $i => $row) {
            foreach ($row as $j => $value) {
                if ($value > $max[$i][$j]) {
                    $this->fail("allocation.$i.$j", 'Allocation no puede superar Max en el proceso '.($i + 1).', recurso '.($j + 1).'.');
                }
            }
        }

        return new DeadlockScenario($n, $m, $allocation, $max, $available);
    }

    public function checkPayloadSize(array $input): void
    {
        $encoded = json_encode($input);
        abort_if($encoded === false || strlen($encoded) > self::MAX_PAYLOAD_BYTES, 413, 'El escenario excede el límite de 32 KiB.');
    }

    public function processPosition(mixed $value, int $count): int
    {
        return $this->integer($value, 'process', 1, $count) - 1;
    }

    public function resourceVector(mixed $value, string $field, int $count): array
    {
        return $this->vector($this->decode($value, $field), $field, $count);
    }

    private function decode(mixed $value, string $field): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        try {
            // Keep JSON objects distinct from ordered arrays, including empty objects.
            return json_decode($value, false, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->fail($field, "El campo $field debe ser una lista JSON válida.");
        }
    }

    private function matrix(mixed $value, string $field, int $n, int $m): array
    {
        $value = $this->decode($value, $field);
        if (! is_array($value) || ! array_is_list($value) || count($value) !== $n) {
            $this->fail($field, "La matriz $field debe tener exactamente $n filas en orden.");
        }

        return array_map(fn ($row, $i) => $this->vector($row, "$field.$i", $m), $value, array_keys($value));
    }

    private function vector(mixed $value, string $field, int $m): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) !== $m) {
            $this->fail($field, "El campo $field debe tener exactamente $m elementos en orden.");
        }

        return array_map(fn ($cell, $j) => $this->integer($cell, "$field.$j", 0, self::MAX_VALUE), $value, array_keys($value));
    }

    private function integer(mixed $value, string $field, int $min, int $max): int
    {
        if ((! is_int($value) && ! is_string($value))
            || ! preg_match('/\A(?:0|[1-9][0-9]*)\z/', (string) $value)
            || strlen((string) $value) > strlen((string) $max)
            || (int) $value < $min || (int) $value > $max) {
            $this->fail($field, "El campo $field debe ser un entero entre $min y $max, sin signos, decimales ni ceros iniciales.");
        }

        return (int) $value;
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
