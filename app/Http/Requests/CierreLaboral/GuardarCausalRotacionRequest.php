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
            'nombre' => ['required', 'string', 'min:3', 'max:80'],
            'descripcion' => ['nullable', 'string', 'max:300'],
            'requiere_juridico' => ['nullable', 'boolean'],
            'comentario_obligatorio' => ['nullable', 'boolean'],
            'alerta' => ['nullable', 'string', 'max:200'],
            'fundamento_legal' => ['nullable', 'string', 'max:255'],
            'excluye_indemnizacion' => ['nullable', 'boolean'],
            'implica_indemnizacion' => ['nullable', 'boolean'],
            'activo' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tipo.required' => 'Elige la forma de salida: renuncia voluntaria, no renovación o rescisión.',
            'tipo.enum' => 'Elige la forma de salida: renuncia voluntaria, no renovación o rescisión.',
            'nombre.required' => 'Escribe el nombre de la causal.',
            'nombre.min' => 'El nombre de la causal debe tener al menos 3 letras.',
            'descripcion.max' => 'La ayuda («cuándo usarla») admite hasta 300 caracteres.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function datos(): array
    {
        return [
            'tipo' => (string) $this->validated('tipo'),
            'nombre' => (string) $this->validated('nombre'),
            'descripcion' => $this->filled('descripcion') ? (string) $this->validated('descripcion') : null,
            'requiere_juridico' => $this->has('requiere_juridico') ? $this->boolean('requiere_juridico') : null,
            'comentario_obligatorio' => $this->has('comentario_obligatorio') ? $this->boolean('comentario_obligatorio') : null,
            'alerta' => $this->filled('alerta') ? (string) $this->validated('alerta') : null,
            'fundamento_legal' => $this->filled('fundamento_legal') ? (string) $this->validated('fundamento_legal') : null,
            'excluye_indemnizacion' => $this->boolean('excluye_indemnizacion'),
            'implica_indemnizacion' => $this->boolean('implica_indemnizacion'),
            'activo' => $this->has('activo') ? $this->boolean('activo') : null,
        ];
    }
}
