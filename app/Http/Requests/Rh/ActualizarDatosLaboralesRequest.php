<?php

namespace App\Http\Requests\Rh;

use App\Models\Colaborador;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Cambia empresa (vía sucursal), sucursal, departamento, puesto o sueldo
 * mensual de un colaborador SIN pasar por una vacante — a diferencia de
 * VacanteController::cubrir(), que sí crea/cierra una vacante. Siempre
 * exclusivo de RH (nunca autoservicio, ver ExpedienteController): un cambio
 * de puesto/sucursal es una decisión organizacional, no un dato personal.
 *
 * El jefe directo NO se captura: sale del organigrama según el puesto y la
 * sucursal (App\Services\Organigrama\JefeDirectoService).
 */
class ActualizarDatosLaboralesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $colaborador = $this->route('colaborador');

        // Nunca sobre el propio expediente (ver ActualizarDatosPersonalesRequest).
        $usuario = $this->user();

        if (! $usuario instanceof User || ! $colaborador instanceof Colaborador || $usuario->colaborador_id === $colaborador->id) {
            return false;
        }

        // Permiso de acción + alcance del módulo «Expedientes» sobre ESTE
        // colaborador (mismo criterio que la vista del expediente).
        return $usuario->can('expedientes.editar')
            && app(AlcanceOrganizacionalService::class)->puedeVerExpediente($usuario, $colaborador);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'sucursal_principal_id' => ['nullable', 'integer', 'exists:sucursales,id'],
            'departamento_id' => ['nullable', 'integer', 'exists:departamentos,id'],
            'puesto_id' => ['nullable', 'integer', 'exists:puestos,id'],
            'sueldo_mensual' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'motivo' => ['nullable', 'string', 'max:255'],
        ];
    }
}
