<?php

namespace App\Http\Requests\Solicitudes;

use App\Enums\TipoBaja;
use App\Enums\TipoSolicitudInterna;
use App\Models\SolicitudInterna;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSolicitudInternaRequest extends FormRequest
{
    /**
     * Baja de colaborador usa su propio permiso (`solicitudes.bajas.crear`,
     * ver App\Enums\TipoSolicitudInterna::requiereColaboradorObjetivo()): un
     * gerente puede solicitar la baja de su equipo sin necesariamente poder
     * crear otros tipos de solicitud sobre sí mismo. La validación fina de
     * si puede pedir la baja de ESE colaborador en particular vive en
     * App\Services\Solicitudes\SolicitudesService::crear() (defensa en
     * profundidad, mismo criterio que SolicitudInternaPolicy::crearBaja()).
     */
    public function authorize(): bool
    {
        $usuario = $this->user();

        if ($usuario === null) {
            return false;
        }

        $tipo = TipoSolicitudInterna::tryFrom((string) $this->input('tipo'));

        if ($tipo === TipoSolicitudInterna::BajaColaborador) {
            return $usuario->can('solicitudes.bajas.crear');
        }

        return $usuario->can('create', SolicitudInterna::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tipo = TipoSolicitudInterna::tryFrom((string) $this->input('tipo'));

        return [
            'tipo' => ['required', 'string', Rule::in(array_column(TipoSolicitudInterna::cases(), 'value'))],
            'motivo' => ['required', 'string', 'max:2000'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
            'fecha_inicio' => [
                Rule::requiredIf($tipo?->usaRangoFechas() === true || $tipo?->usaHorario() === true),
                'nullable',
                'date',
            ],
            'fecha_fin' => [
                Rule::requiredIf($tipo?->usaRangoFechas() === true),
                'nullable',
                'date',
                'after_or_equal:fecha_inicio',
            ],
            'dias_solicitados' => [Rule::requiredIf($tipo?->requiereDias() === true), 'nullable', 'integer', 'min:1', 'max:365'],
            'monto_solicitado' => [Rule::requiredIf($tipo?->requiereMonto() === true), 'nullable', 'numeric', 'min:1'],
            'plazo_meses' => ['nullable', 'integer', 'min:1', 'max:36'],
            'colaborador_objetivo_id' => [
                Rule::requiredIf($tipo?->requiereColaboradorObjetivo() === true),
                'nullable',
                'integer',
                'exists:users,id',
            ],
            'fecha_efectiva' => [
                Rule::requiredIf($tipo?->requiereColaboradorObjetivo() === true),
                'nullable',
                'date',
            ],
            'tipo_baja' => [
                Rule::requiredIf($tipo?->requiereColaboradorObjetivo() === true),
                'nullable',
                'string',
                Rule::in(array_column(TipoBaja::cases(), 'value')),
            ],
        ];
    }
}
