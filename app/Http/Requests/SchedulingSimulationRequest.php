<?php

namespace App\Http\Requests;

use App\Services\Scheduling\SchedulingSimulation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SchedulingSimulationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'algorithm' => ['required', 'string', Rule::in(array_keys(SchedulingSimulation::ALGORITHMS))],
            'quantum' => [
                'exclude_unless:algorithm,round_robin', 'bail', 'required',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_int($value) && ! is_string($value)) {
                        $fail('El quantum debe ser un entero mayor que cero.');
                    }
                },
                'integer', 'regex:/\A[0-9]+\z/', 'min:1',
            ],
        ];
    }
}
