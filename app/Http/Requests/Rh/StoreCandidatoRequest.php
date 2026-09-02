<?php

namespace App\Http\Requests\Rh;

use App\Models\Candidato;
use Illuminate\Foundation\Http\FormRequest;

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
            'vacante_id' => ['nullable', 'integer', 'exists:vacantes,id'],
            'nombre' => ['required', 'string', 'max:150'],
            'apellidos' => ['nullable', 'string', 'max:150'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'correo' => ['nullable', 'email', 'max:255'],
            'fuente' => ['nullable', 'string', 'max:100'],
            'observaciones' => ['nullable', 'string', 'max:4000'],
            'responsable_rh_id' => ['nullable', 'integer', 'exists:users,id'],
            'gerente_involucrado_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}
