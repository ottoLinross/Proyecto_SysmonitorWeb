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
        $mediaType = strtolower(trim(explode(';', (string) $this->header('Content-Type'))[0]));
        $isJson = $mediaType === 'application/json'
            || preg_match('/\Aapplication\/[a-z0-9!#$&^_.+-]+\+json\z/', $mediaType) === 1;
        abort_unless($isJson || $mediaType === 'application/x-www-form-urlencoded', 415, 'Usa un formulario URL-encoded o un objeto JSON, sin archivos.');

        // Read original values so global trimming cannot turn ambiguous numbers into valid ones.
        if ($isJson) {
            $input = json_decode($body, false, 8);
            if (! $input instanceof \stdClass) {
                throw ValidationException::withMessages(['scenario' => 'El cuerpo debe ser un objeto JSON válido.']);
            }

            return get_object_vars($input);
        }
        if ($body !== '') {
            // PHP warns and truncates forms exceeding its field/nesting limits. Reject instead.
            set_error_handler(static function (): never {
                throw ValidationException::withMessages(['scenario' => 'El formulario excede los límites de campos o anidación.']);
            });
            try {
                parse_str($body, $input);
            } finally {
                restore_error_handler();
            }

            return $input;
        }

        // Parameter-only form requests (including framework tests): never merge query parameters.
        return $this->request->all();
    }
}
