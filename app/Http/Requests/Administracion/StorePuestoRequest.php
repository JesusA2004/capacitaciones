<?php

namespace App\Http\Requests\Administracion;

use App\Models\Puesto;
use Illuminate\Foundation\Http\FormRequest;

class StorePuestoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Puesto::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:150'],
            'departamento_id' => ['nullable', 'integer', 'exists:departamentos,id'],
            'descripcion' => ['nullable', 'string', 'max:1000'],
            'activo' => ['boolean'],
            // Duración del contrato de capacitación inicial (periodo de prueba)
            // en meses; sin ella no se calculan contratos ni vencimientos.
            'meses_periodo_prueba' => ['nullable', 'integer', 'min:1', 'max:12'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['meses_periodo_prueba' => 'duración del periodo de prueba (meses)'];
    }
}
