<?php

namespace App\Services\Scheduling;

use Illuminate\Support\Facades\Validator;

class ProcessInput
{
    public const MAX_PROCESSES = 50;

    public const MAX_ID_LENGTH = 32;

    public const MAX_ARRIVAL = 10000;

    public const MAX_BURST = 1000;

    public const MIN_PRIORITY = 1;

    public const MAX_PRIORITY = 10;

    /** @return list<SchedulingProcess> */
    public function validate(array $input): array
    {
        $validated = Validator::make($input, [
            'processes' => ['required', 'array', 'list', 'min:1', 'max:'.self::MAX_PROCESSES],
            'processes.*' => ['required', 'array:id,arrival_time,burst_time,priority'],
            'processes.*.id' => ['required', 'string', 'max:'.self::MAX_ID_LENGTH, 'distinct:strict', 'regex:/\A\S(?:[^\r\n]*\S)?\z/u'],
            'processes.*.arrival_time' => $this->integerRules(0, self::MAX_ARRIVAL),
            'processes.*.burst_time' => $this->integerRules(1, self::MAX_BURST),
            'processes.*.priority' => $this->integerRules(self::MIN_PRIORITY, self::MAX_PRIORITY),
        ])->validate();

        return array_map(fn (array $process): SchedulingProcess => new SchedulingProcess(
            $process['id'],
            (int) $process['arrival_time'],
            (int) $process['burst_time'],
            (int) $process['priority'],
        ), $validated['processes']);
    }

    public function validateCount(array $input): int
    {
        $validated = Validator::make($input, [
            'count' => $this->integerRules(1, self::MAX_PROCESSES),
        ])->validate();

        return (int) $validated['count'];
    }

    private function integerRules(int $min, int $max): array
    {
        return [
            'bail',
            'required',
            function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_int($value) && ! is_string($value)) {
                    $fail('El campo :attribute debe ser un entero.');
                }
            },
            'integer',
            'regex:/\A[0-9]+\z/',
            'min:'.$min,
            'max:'.$max,
        ];
    }
}
