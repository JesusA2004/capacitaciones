<?php

namespace App\Http\Requests\Rh;

use Illuminate\Foundation\Http\FormRequest;

class StoreIncorporacionInvitacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('rh.incorporacion.invitaciones.crear') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nombre_prellenado' => ['nullable', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'empresa_id' => ['nullable', 'integer', 'exists:empresas,id'],
            'sucursal_id' => ['nullable', 'integer', 'exists:sucursales,id'],
            'departamento_id' => ['nullable', 'integer', 'exists:departamentos,id'],
            'puesto_id' => ['nullable', 'integer', 'exists:puestos,id'],
            'candidato_id' => ['nullable', 'integer', 'exists:candidatos,id'],
            // Alternativa a duracion_horas: fecha exacta de expiracion.
            'expires_at' => ['nullable', 'date', 'after:now'],
            'duracion_horas' => ['nullable', 'integer', 'min:1', 'max:8760'],
            'max_usos' => ['nullable', 'integer', 'min:1', 'max:100'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
