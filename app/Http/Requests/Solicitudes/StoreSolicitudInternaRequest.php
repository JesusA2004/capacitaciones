<?php

namespace App\Http\Requests\Solicitudes;

use App\Enums\CausalPermisoEspecial;
use App\Enums\GocePermiso;
use App\Enums\ModoFechasSolicitud;
use App\Enums\TipoBaja;
use App\Enums\TipoPermisoSolicitado;
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
        $esPermiso = $tipo === TipoSolicitudInterna::Permiso;
        $permisoTipo = TipoPermisoSolicitado::tryFrom((string) $this->input('permiso_tipo'));
        // Permiso: «faltar» se pide por días; salir temprano / llegar tarde, un día con hora.
        $modo = $esPermiso && $permisoTipo !== null && $permisoTipo !== TipoPermisoSolicitado::Faltar
            ? ModoFechasSolicitud::Horario
            : $tipo?->modoFechas();
        // App anterior: mandaba fecha_inicio + fecha_fin; se acepta y el
        // backend lo traduce a la misma regla (FechasSolicitudService).
        $legacyRango = $this->filled('fecha_fin');

        return [
            // Solo la taxonomía vigente; los tipos históricos ya no se crean.
            'tipo' => ['required', 'string', Rule::in(TipoSolicitudInterna::valoresCreables())],
            // El permiso pide solo lo del formato: sus observaciones bastan.
            'motivo' => [Rule::requiredIf(! $esPermiso), 'nullable', 'string', 'max:2000'],
            'permiso_tipo' => [Rule::requiredIf($esPermiso), Rule::prohibitedIf(! $esPermiso), 'nullable', Rule::enum(TipoPermisoSolicitado::class)],
            'permiso_goce' => [Rule::requiredIf($esPermiso), Rule::prohibitedIf(! $esPermiso), 'nullable', Rule::enum(GocePermiso::class)],
            'permiso_causal' => [
                Rule::requiredIf($esPermiso && $this->input('permiso_goce') === GocePermiso::Especial->value),
                Rule::prohibitedIf(! $esPermiso || $this->input('permiso_goce') !== GocePermiso::Especial->value),
                'nullable',
                Rule::enum(CausalPermisoEspecial::class),
            ],
            'hora_salida' => [Rule::requiredIf($permisoTipo === TipoPermisoSolicitado::SalirTemprano), 'nullable', 'date_format:H:i'],
            'hora_entrada' => [Rule::requiredIf($permisoTipo === TipoPermisoSolicitado::LlegarTarde), 'nullable', 'date_format:H:i'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
            'fecha_inicio' => [
                Rule::requiredIf(in_array($modo, [ModoFechasSolicitud::Duracion, ModoFechasSolicitud::Horario, ModoFechasSolicitud::FechaUnica], true)
                    || ($modo === ModoFechasSolicitud::DiasEspecificos && $legacyRango)),
                'nullable',
                'date',
            ],
            // Nunca es la fuente: el backend calcula la fecha fin.
            'fecha_fin' => ['nullable', 'date'],
            'duracion_dias' => [
                Rule::requiredIf($modo === ModoFechasSolicitud::Duracion && ! $legacyRango),
                'nullable', 'integer', 'min:1', 'max:365',
            ],
            'dias' => [
                Rule::requiredIf($modo === ModoFechasSolicitud::DiasEspecificos && ! $legacyRango),
                'nullable', 'array', 'min:1', 'max:60',
            ],
            'dias.*' => ['date_format:Y-m-d'],
            // Se calcula (cuántos días). Se acepta de clientes viejos, pero se ignora.
            'dias_solicitados' => ['nullable', 'integer', 'min:1', 'max:365'],
            'monto_solicitado' => [Rule::requiredIf($tipo?->requiereMonto() === true), 'nullable', 'numeric', 'min:1'],
            'plazo_meses' => ['nullable', 'integer', 'min:1', 'max:36'],
            'colaborador_objetivo_id' => [
                Rule::requiredIf($tipo?->requiereColaboradorObjetivo() === true),
                'nullable',
                'integer',
                'exists:colaboradores,id',
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

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'fecha_inicio.required' => 'Indica la fecha de inicio.',
            'fecha_inicio.date' => 'Indica una fecha de inicio válida.',
            'duracion_dias.required' => 'Indica el número de días.',
            'duracion_dias.min' => 'El número de días debe ser al menos 1.',
            'duracion_dias.max' => 'El número de días no puede pasar de 365.',
            'dias.required' => 'Selecciona al menos un día.',
            'dias.min' => 'Selecciona al menos un día.',
            'dias.max' => 'Puedes pedir a lo más 60 días en una solicitud.',
            'dias.*.date_format' => 'Uno de los días seleccionados no es una fecha válida.',
            'tipo.in' => 'Ese tipo de solicitud ya no está disponible: elige Permiso, Vacaciones o Préstamo.',
            'permiso_tipo.required' => 'Elige qué permiso necesitas: faltar, salir temprano o llegar tarde.',
            'permiso_goce.required' => 'Elige el tipo de permiso: con goce, sin goce o especial.',
            'permiso_causal.required' => 'Elige la causal del permiso especial.',
            'permiso_causal.prohibited' => 'La causal solo aplica a un permiso especial.',
            'hora_salida.required' => 'Indica a qué hora sales.',
            'hora_entrada.required' => 'Indica a qué hora llegas.',
            'hora_salida.date_format' => 'Indica la hora como HH:MM.',
            'hora_entrada.date_format' => 'Indica la hora como HH:MM.',
        ];
    }
}
