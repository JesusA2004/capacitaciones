<?php

namespace App\Http\Requests\Administracion;

use App\Models\Colaborador;
use App\Services\Organigrama\MovimientoOrganigramaService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Subir / bajar / mover a una persona desde el organigrama (vista previa y
 * aplicar). Autoriza ColaboradorPolicy::moverEnOrganigrama (solo RH).
 */
class MoverEnOrganigramaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $colaborador = $this->route('colaborador');

        return $colaborador instanceof Colaborador
            && $this->user() !== null
            && $this->user()->can('moverEnOrganigrama', $colaborador);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'accion' => ['required', Rule::in(MovimientoOrganigramaService::ACCIONES)],
            'puesto_id' => ['required', 'integer', Rule::exists('puestos', 'id')->where('activo', true)],
            'sucursal_id' => ['nullable', 'integer', Rule::exists('sucursales', 'id')],
            'region_id' => ['nullable', 'integer', Rule::exists('nodos_comerciales', 'id')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'accion.required' => 'Indica si vas a subir, bajar o mover a la persona.',
            'puesto_id.required' => 'Elige a qué puesto pasa.',
            'puesto_id.exists' => 'Elige un puesto activo del catálogo.',
            'sucursal_id.exists' => 'La sucursal elegida no existe.',
            'region_id.exists' => 'Elige Q1 o Q3.',
        ];
    }

    /**
     * @return array{puesto_id: int, sucursal_id: int|null, region_id: int|null}
     */
    public function destino(): array
    {
        return [
            'puesto_id' => $this->integer('puesto_id'),
            'sucursal_id' => $this->filled('sucursal_id') ? $this->integer('sucursal_id') : null,
            'region_id' => $this->filled('region_id') ? $this->integer('region_id') : null,
        ];
    }
}
