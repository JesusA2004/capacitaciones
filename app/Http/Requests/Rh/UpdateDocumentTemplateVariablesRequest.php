<?php

namespace App\Http\Requests\Rh;

use App\Models\DocumentTemplate;
use App\Services\Plantillas\VariableMappingService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Guarda el catálogo de variables manuales de una plantilla (Portal RH →
 * Formatos → Plantillas avanzadas → "Variables"). Cada `clave` debe ser un
 * marcador {{...}} que de verdad aparece en el DOCX y que todavía no
 * corresponde a ningún dato real del colaborador/candidato — nunca se puede
 * declarar como manual una variable ya conocida (PlaceholderResolver), ni
 * una clave que no exista en el archivo.
 */
class UpdateDocumentTemplateVariablesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('plantilla')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'variables' => ['present', 'array'],
            'variables.*.clave' => ['required', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_]+$/', 'distinct'],
            'variables.*.etiqueta' => ['required', 'string', 'max:150'],
            'variables.*.descripcion' => ['nullable', 'string', 'max:500'],
            'variables.*.tipo' => ['required', Rule::in(['text', 'textarea', 'date', 'number', 'currency', 'select'])],
            'variables.*.requerido' => ['boolean'],
            'variables.*.valor_por_defecto' => ['nullable', 'string', 'max:255'],
            'variables.*.opciones' => ['nullable', 'array', 'required_if:variables.*.tipo,select'],
            'variables.*.opciones.*' => ['string', 'max:150'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $plantilla = $this->route('plantilla');
            if (! $plantilla instanceof DocumentTemplate) {
                return;
            }

            $mapeo = app(VariableMappingService::class);
            // El universo válido son los marcadores del DOCX que no son un
            // dato real conocido — incluye tanto los que ya están mapeados
            // como manuales (para poder editarlos) como los que todavía no.
            $clavesValidas = $mapeo->clavesNoConocidas($plantilla);

            foreach ($this->input('variables', []) as $indice => $definicion) {
                $clave = $definicion['clave'] ?? null;
                if ($clave !== null && ! in_array($clave, $clavesValidas, true)) {
                    $validator->errors()->add(
                        "variables.{$indice}.clave",
                        "«{$clave}» no aparece como marcador sin mapear en el DOCX de esta plantilla.",
                    );
                }
            }
        });
    }
}
