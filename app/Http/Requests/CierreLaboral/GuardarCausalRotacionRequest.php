<?php

namespace App\Http\Requests\CierreLaboral;

use App\Enums\TipoBaja;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta / edición de una causal de rotación (Bajas y finiquitos → «Causales
 * de rotación»). La autorización vive en CausalRotacionPolicy y la
 * unicidad por tipo y las reglas de uso en CausalRotacionService.
 */
class GuardarCausalRotacionRequest extends FormRequest
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
            'tipo' => ['required', Rule::enum(TipoBaja::class)],
            'nombre' => ['required', 'string', 'min:3', 'max:150'],
            'descripcion' => ['nullable', 'string', 'max:500'],
            'excluye_indemnizacion' => ['nullable', 'boolean'],
            'activo' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tipo.required' => 'Elige el tipo de baja: renuncia, rescisión o fin de contrato.',
            'tipo.enum' => 'Elige el tipo de baja: renuncia, rescisión o fin de contrato.',
            'nombre.required' => 'Escribe el nombre del motivo.',
            'nombre.min' => 'El nombre del motivo debe tener al menos 3 letras.',
        ];
    }

    /**
     * @return array{tipo: string, nombre: string, descripcion?: string|null, excluye_indemnizacion?: bool|null, activo?: bool|null}
     */
    public function datos(): array
    {
        return [
            'tipo' => (string) $this->validated('tipo'),
            'nombre' => (string) $this->validated('nombre'),
            'descripcion' => $this->filled('descripcion') ? (string) $this->validated('descripcion') : null,
            'excluye_indemnizacion' => $this->boolean('excluye_indemnizacion'),
            'activo' => $this->has('activo') ? $this->boolean('activo') : null,
        ];
    }
}
