<?php

namespace App\Http\Requests\Administracion;

use App\Enums\MotivoCobertura;
use App\Models\CoberturaPuesto;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Asignar quién cubre temporalmente un puesto de otra sucursal/región desde
 * el organigrama. Mismo permiso que editar la jerarquía de puestos.
 */
class StoreCoberturaPuestoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $usuario = $this->user();

        // Solo RH / super admin (CoberturaPuestoPolicy::create).
        return $usuario !== null && $usuario->can('create', CoberturaPuesto::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'colaborador_id' => ['required', 'integer', Rule::exists('colaboradores', 'id')],
            'puesto_id' => ['required', 'integer', Rule::exists('puestos', 'id')->where('activo', true)],
            'sucursal_id' => ['nullable', 'integer', Rule::exists('sucursales', 'id')],
            'region_id' => ['nullable', 'integer', Rule::exists('nodos_comerciales', 'id')],
            // «Cubre temporalmente este puesto»: sin motivo ni fechas (el
            // servicio usa «Puesto vacante» y la fecha de hoy).
            'motivo' => ['nullable', Rule::enum(MotivoCobertura::class)],
            'nota' => ['nullable', 'string', 'max:500'],
            'fecha_inicio' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'colaborador_id.required' => 'Elige a quién va a cubrir el puesto.',
        ];
    }
}
