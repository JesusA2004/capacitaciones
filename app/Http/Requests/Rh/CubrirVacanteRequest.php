<?php

namespace App\Http\Requests\Rh;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cubrir una vacante existente en uno de tres modos (ver
 * docs/VACANTES.md): con un candidato externo (solo enlaza, no muta nada
 * aquí — el alta digital hace el resto), con un colaborador interno
 * (promoción/cambio de puesto), o con una cobertura temporal.
 */
class CubrirVacanteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('cambiarEstado', $this->route('vacante')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'modo' => ['required', Rule::in(['candidato_externo', 'colaborador_interno', 'cobertura_temporal'])],
            'user_id' => ['required_if:modo,colaborador_interno,cobertura_temporal', 'nullable', 'integer', 'exists:users,id'],
            'motivo' => ['nullable', 'string', 'max:500'],
            'fecha_inicio' => ['required_if:modo,cobertura_temporal', 'nullable', 'date'],
            'fecha_fin' => ['nullable', 'date', 'after_or_equal:fecha_inicio'],
        ];
    }
}
