<?php

namespace App\Http\Requests\Rh;

use App\Enums\TipoConceptoNomina;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Importes corregidos de un finiquito (vista previa y confirmación). La
 * autorización vive en FiniquitoCalculoPolicy y las reglas en FiniquitoService.
 */
class CorregirFiniquitoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'conceptos' => ['nullable', 'array'],
            'conceptos.*.concepto_clave' => ['required', 'string', 'max:60'],
            'conceptos.*.importe' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'manuales' => ['nullable', 'array'],
            'manuales.*.id' => ['required', 'integer'],
            'manuales.*.importe' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'nuevos' => ['nullable', 'array'],
            'nuevos.*.concepto' => ['required', 'string', 'min:3', 'max:120'],
            'nuevos.*.tipo' => ['required', Rule::enum(TipoConceptoNomina::class)],
            'nuevos.*.importe' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'eliminados' => ['nullable', 'array'],
            'eliminados.*' => ['integer'],
            'motivo' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            '*.*.importe.min' => 'Un importe no puede ser negativo.',
            '*.*.importe.numeric' => 'Revisa los importes: deben ser números.',
            'nuevos.*.concepto.required' => 'Escribe el nombre del concepto nuevo.',
            'nuevos.*.concepto.min' => 'El nombre del concepto debe tener al menos 3 letras.',
        ];
    }

    /**
     * @return array{conceptos: array<int, array<string, mixed>>, manuales: array<int, array<string, mixed>>, nuevos: array<int, array<string, mixed>>, eliminados: array<int, int|string>}
     */
    public function cambios(): array
    {
        return [
            'conceptos' => (array) $this->validated('conceptos', []),
            'manuales' => (array) $this->validated('manuales', []),
            'nuevos' => (array) $this->validated('nuevos', []),
            'eliminados' => (array) $this->validated('eliminados', []),
        ];
    }
}
