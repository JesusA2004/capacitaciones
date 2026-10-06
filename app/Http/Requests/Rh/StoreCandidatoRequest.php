<?php

namespace App\Http\Requests\Rh;

use App\Enums\FuenteCandidato;
use App\Models\Candidato;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCandidatoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Candidato::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'empresa_id' => ['nullable', 'integer', 'exists:empresas,id'],
            'sucursal_id' => ['nullable', 'integer', 'exists:sucursales,id'],
            'departamento_id' => ['nullable', 'integer', 'exists:departamentos,id'],
            'puesto_objetivo_id' => ['nullable', 'integer', 'exists:puestos,id'],
            'espontaneo' => ['sometimes', 'boolean'],
            // Sin vacante solo si el candidato es explícitamente espontáneo
            // (CLAUDE.md §3): ya no existe el "pipeline general" implícito.
            'vacante_id' => [Rule::requiredIf(fn () => ! $this->boolean('espontaneo')), 'nullable', 'integer', 'exists:vacantes,id'],
            'campana_reclutamiento_id' => ['nullable', 'integer', 'exists:campanas_reclutamiento,id'],
            'nombre' => ['required', 'string', 'max:150'],
            'apellidos' => ['nullable', 'string', 'max:150'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'correo' => ['nullable', 'email', 'max:255'],
            'fuente' => ['nullable', 'string', Rule::in(FuenteCandidato::valores())],
            'observaciones' => ['nullable', 'string', 'max:4000'],
            'responsable_rh_id' => ['nullable', 'integer', 'exists:users,id'],
            'gerente_involucrado_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}
