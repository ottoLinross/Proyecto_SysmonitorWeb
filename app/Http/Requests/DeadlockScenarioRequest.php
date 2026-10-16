<?php

namespace App\Http\Requests;

use App\Services\Deadlock\ScenarioInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class DeadlockScenarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }

    public function scenarioInput(): array
    {
        $body = $this->getContent();
        abort_if(strlen($body) > ScenarioInput::MAX_PAYLOAD_BYTES
            || (int) $this->header('Content-Length', 0) > ScenarioInput::MAX_PAYLOAD_BYTES
            || $this->allFiles() !== [], 413, 'El escenario excede el límite de 32 KiB o contiene archivos.');
        abort_if(str_starts_with((string) $this->header('Content-Type'), 'multipart/form-data'), 415, 'Usa un formulario URL-encoded o un objeto JSON, sin archivos.');

        // Read original values so global trimming cannot turn ambiguous numbers into valid ones.
        if ($body !== '' && $this->isJson()) {
            $input = json_decode($body, false, 8);
            if (! $input instanceof \stdClass) {
                throw ValidationException::withMessages(['scenario' => 'El cuerpo debe ser un objeto JSON válido.']);
            }

            return get_object_vars($input);
        }
        if ($body !== '' && str_starts_with((string) $this->header('Content-Type'), 'application/x-www-form-urlencoded')) {
            parse_str($body, $input);

            return $input;
        }

        return $this->all();
    }
}
