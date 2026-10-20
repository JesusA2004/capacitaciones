<?php

namespace App\Http\Requests\Administracion;

use App\Models\Colaborador;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Clasificar a un Gerente de Sucursal en Q1 o Q3 (region_id vacío lo regresa
 * a «Por clasificar»). Autoriza ColaboradorPolicy::clasificarRegion.
 */
class ClasificarRegionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $colaborador = $this->route('colaborador');

        return $colaborador instanceof Colaborador
            && $this->user() !== null
            && $this->user()->can('clasificarRegion', $colaborador);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'region_id' => ['nullable', 'integer', Rule::exists('nodos_comerciales', 'id')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'region_id.exists' => 'Elige Q1 o Q3.',
        ];
    }

    public function regionId(): ?int
    {
        return $this->filled('region_id') ? $this->integer('region_id') : null;
    }
}
