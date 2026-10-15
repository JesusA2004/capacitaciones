<?php

namespace App\Http\Requests\Rh;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Estructura corta que el Excel no trae (CLAUDE.md §76): puesto es
 * obligatorio, departamento/sucursal/número de empleado son opcionales
 * (personal corporativo o remoto puede no tener sucursal operativa).
 */
class CrearColaboradorImportacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('colaboradores.importar_salarios_vacaciones') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'puesto_id' => ['required', 'integer', 'exists:puestos,id'],
            'departamento_id' => ['nullable', 'integer', 'exists:departamentos,id'],
            'sucursal_principal_id' => ['nullable', 'integer', 'exists:sucursales,id'],
            'numero_empleado' => ['nullable', 'string', 'max:30'],
            // La fecha del Excel NO es fuente confiable: RH la captura aquí
            // si la tiene validada; si no, queda pendiente de validación.
            'fecha_ingreso' => ['nullable', 'date'],
        ];
    }
}
