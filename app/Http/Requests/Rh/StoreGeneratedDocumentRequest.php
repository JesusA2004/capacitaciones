<?php

namespace App\Http\Requests\Rh;

use App\Models\DocumentTemplate;
use App\Services\Plantillas\VariableMappingService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreGeneratedDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('plantillas.generar') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'document_template_id' => ['required', 'integer', 'exists:document_templates,id'],
            'solicitud_id' => ['nullable', 'integer', 'exists:solicitudes_internas,id', 'prohibits:solicitud_vacaciones_id'],
            'solicitud_vacaciones_id' => ['nullable', 'integer', 'exists:solicitudes_vacaciones,id'],
            'tipo_sujeto' => ['required_without_all:solicitud_id,solicitud_vacaciones_id', 'nullable', Rule::in(['colaborador', 'candidato'])],
            'sujeto_id' => ['required_without_all:solicitud_id,solicitud_vacaciones_id', 'nullable', 'integer'],
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

            $plantilla = DocumentTemplate::query()->where('id', $this->input('document_template_id'))->first();
            if ($plantilla === null) {
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
