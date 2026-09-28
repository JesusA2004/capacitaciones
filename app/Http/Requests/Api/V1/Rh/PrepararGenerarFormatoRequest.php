<?php

namespace App\Http\Requests\Api\V1\Rh;

use App\Models\DocumentTemplate;
use App\Services\Plantillas\VariableMappingService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Cuerpo común de `preparar()`/`generar()` en la API móvil de RH para el
 * motor de plantillas DOCX (App\Http\Controllers\Api\V1\Rh\FormatoController)
 * — mismas reglas que `App\Http\Requests\Rh\PreviewFormatoRequest`/
 * `StoreGeneratedDocumentRequest` del panel web, adaptadas a que la
 * plantilla llega por la ruta ({plantilla}) en vez del cuerpo.
 */
class PrepararGenerarFormatoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $plantilla = $this->route('plantilla');

        return $plantilla instanceof DocumentTemplate && ($this->user()?->can('generar', $plantilla) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tipo_sujeto' => ['required', Rule::in(['colaborador', 'candidato'])],
            'sujeto_id' => ['required', 'integer'],
            'extra' => ['nullable', 'array'],
            'extra.*' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $extra = $this->input('extra', []);
            if (! is_array($extra) || $extra === []) {
                return;
            }

            $plantilla = $this->route('plantilla');
            if (! $plantilla instanceof DocumentTemplate) {
                return;
            }

            $permitidas = app(VariableMappingService::class)->clavesPermitidasEnExtra($plantilla);
            foreach (array_keys($extra) as $clave) {
                if (! in_array($clave, $permitidas, true)) {
                    $validator->errors()->add('extra', "«{$clave}» no es una variable válida para esta plantilla.");
                }
            }
        });
    }
}
