<?php

namespace App\Http\Requests\Rh;

use App\Models\AltaDigital;
use Illuminate\Foundation\Http\FormRequest;

class StoreAltaDigitalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', AltaDigital::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'candidato_id' => ['nullable', 'integer', 'exists:candidatos,id'],
            'vacante_id' => ['nullable', 'integer', 'exists:vacantes,id'],
            'empresa_id' => ['nullable', 'integer', 'exists:empresas,id'],
            'sucursal_id' => ['nullable', 'integer', 'exists:sucursales,id'],
            'departamento_id' => ['nullable', 'integer', 'exists:departamentos,id'],
            'puesto_id' => ['nullable', 'integer', 'exists:puestos,id'],
            'nombre' => ['nullable', 'string', 'max:150'],
            'apellidos' => ['nullable', 'string', 'max:150'],
            'correo' => ['nullable', 'email', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'fecha_ingreso_propuesta' => ['nullable', 'date'],
        ];
    }
}
