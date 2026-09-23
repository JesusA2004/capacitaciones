<?php

namespace App\Http\Requests\Solicitudes;

use App\Enums\TipoSolicitudInterna;
use App\Models\SolicitudInterna;
use Illuminate\Validation\Rule;

/**
 * Solicitud creada por el propio colaborador desde la app móvil
 * (`POST /api/v1/solicitudes`, `POST /api/v1/colaborador/solicitudes`).
 *
 * Reglas de negocio del autoservicio:
 * - Un colaborador NO solicita bajas: `baja_colaborador` no es un tipo
 *   creable aquí (la baja laboral es un proceso administrativo de RH /
 *   Dirección — cierre laboral, finiquito — y la solicitud de baja de un
 *   subordinado sigue disponible en el Portal web para quien tenga
 *   `solicitudes.bajas.crear`).
 * - Préstamo: el colaborador solo indica monto y motivo. Plazo,
 *   periodicidad y condiciones los decide RH al autorizar; cualquier
 *   `plazo_meses` que mande un cliente viejo se descarta, no se guarda.
 */
class StoreSolicitudAutoservicioRequest extends StoreSolicitudInternaRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', SolicitudInterna::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'tipo' => ['required', 'string', Rule::in(TipoSolicitudInterna::valoresAutoservicio())],
            'plazo_meses' => ['exclude'],
            'colaborador_objetivo_id' => ['exclude'],
            'fecha_efectiva' => ['exclude'],
            'tipo_baja' => ['exclude'],
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tipo.in' => 'Ese tipo de solicitud no está disponible.',
        ];
    }
}
