<?php

namespace App\Http\Requests\Rh;

use App\Models\Colaborador;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

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
            // Fecha de ingreso: de ella salen TODAS las fechas del contrato.
            // Vacía nunca borra la que ya existe (DatosExpedienteService).
            'fecha_ingreso' => ['sometimes', 'nullable', 'date', 'before_or_equal:+1 year', 'after:1950-01-01'],
            'motivo' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'fecha_ingreso.date' => 'Escribe una fecha de ingreso válida.',
            'fecha_ingreso.before_or_equal' => 'Revisa la fecha de ingreso: está demasiado en el futuro.',
            'fecha_ingreso.after' => 'Revisa la fecha de ingreso.',
        ];
    }

    /**
     * El sueldo y la fecha de ingreso son datos críticos
     * (DatosExpedienteService::CAMPOS_CRITICOS): cambiarlos exige motivo.
     * Un campo vacío nunca cambia el sueldo (ver
     * DatosExpedienteService::actualizarLaborales), así que no lo exige.
     *
     * @return array<int, Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $colaborador = $this->route('colaborador');
                $sueldo = $this->input('sueldo_mensual');

                if ($validator->errors()->isNotEmpty() || ! $colaborador instanceof Colaborador || trim((string) $this->input('motivo')) !== '') {
                    return;
                }

                $cambiaSueldo = is_numeric($sueldo) && (float) $sueldo > 0
                    && ($colaborador->sueldo_mensual === null || abs((float) $colaborador->sueldo_mensual - (float) $sueldo) > 0.004);
                $ingreso = $this->input('fecha_ingreso');
                $cambiaIngreso = is_string($ingreso) && $ingreso !== '' && $colaborador->fecha_ingreso?->toDateString() !== substr($ingreso, 0, 10);

                if ($cambiaSueldo || $cambiaIngreso) {
                    $validator->errors()->add('motivo', sprintf('Escribe el motivo del cambio de %s (dato crítico).', $cambiaSueldo && $cambiaIngreso ? 'sueldo y fecha de ingreso' : ($cambiaSueldo ? 'sueldo' : 'fecha de ingreso')));
                }
            },
        ];
    }
}
