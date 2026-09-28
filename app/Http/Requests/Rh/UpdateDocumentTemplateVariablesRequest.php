<?php

namespace App\Http\Requests\Rh;

use App\Models\DocumentTemplate;
use App\Services\Plantillas\PlantillaDocumentoService;
use App\Services\Plantillas\VariableMappingService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Guarda el catálogo de variables de una plantilla (Portal RH → Formatos →
 * Plantillas avanzadas → "Variables"). Cada `clave` debe ser un marcador
 * {{...}} que de verdad aparece en el DOCX de esta plantilla — automático
 * (PlaceholderResolver) o manual, nunca uno inventado.
 *
 * Una variable AUTOMÁTICA (curp, rfc, domicilio, fecha_ingreso...) solo
 * declara `requerido` — su etiqueta/tipo/valor los sigue resolviendo el dato
 * real del colaborador/candidato, esta configuración nunca los sobreescribe.
 * Una variable MANUAL (sin dato real conocido) sigue exigiendo
 * etiqueta/tipo, igual que antes de este cambio.
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
            'variables.*.etiqueta' => ['nullable', 'string', 'max:150'],
            'variables.*.descripcion' => ['nullable', 'string', 'max:500'],
            'variables.*.tipo' => ['nullable', Rule::in(['text', 'textarea', 'date', 'number', 'currency', 'select'])],
            'variables.*.requerido' => ['boolean'],
            'variables.*.valor_por_defecto' => ['nullable', 'string', 'max:255'],
            'variables.*.opciones' => ['nullable', 'array'],
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
            // El universo válido es cualquier marcador que de verdad
            // aparece en el DOCX de esta plantilla — automático o no.
            $detectadas = app(PlantillaDocumentoService::class)->variablesEnPlantilla($plantilla);
            $conocidas = $mapeo->clavesConocidas();

            foreach ($this->input('variables', []) as $indice => $definicion) {
                $clave = $definicion['clave'] ?? null;

                if ($clave !== null && ! in_array($clave, $detectadas, true)) {
                    $validator->errors()->add(
                        "variables.{$indice}.clave",
                        "«{$clave}» no aparece como marcador en el DOCX de esta plantilla.",
                    );

                    continue;
                }

                // Automática: solo importa `requerido`, nada más que validar.
                if ($clave !== null && in_array($clave, $conocidas, true)) {
                    continue;
                }

                // Manual: sigue exigiendo etiqueta y tipo, igual que antes.
                if (trim((string) ($definicion['etiqueta'] ?? '')) === '') {
                    $validator->errors()->add("variables.{$indice}.etiqueta", 'La etiqueta es obligatoria para una variable manual.');
                }

                if (trim((string) ($definicion['tipo'] ?? '')) === '') {
                    $validator->errors()->add("variables.{$indice}.tipo", 'El tipo es obligatorio para una variable manual.');
                }

                if (($definicion['tipo'] ?? null) === 'select' && empty($definicion['opciones'] ?? [])) {
                    $validator->errors()->add("variables.{$indice}.opciones", 'Las opciones son obligatorias para una variable de tipo «Lista de opciones».');
                }
            }
        });
    }
}
