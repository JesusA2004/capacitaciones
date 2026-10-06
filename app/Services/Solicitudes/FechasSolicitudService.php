<?php

namespace App\Services\Solicitudes;

use App\Enums\EstadoSolicitudInterna;
use App\Enums\EstadoSolicitudVacaciones;
use App\Enums\ModoFechasSolicitud;
use App\Enums\TipoSolicitudInterna;
use App\Models\Colaborador;
use App\Models\SolicitudVacacionDia;
use App\Models\SolicitudVacaciones;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * ÚNICA regla de fechas de una solicitud (web y app llaman a lo mismo a
 * través de SolicitudesService::crear()). Nunca se confía en una fecha fin
 * capturada a mano:
 *
 * - DURACIÓN (incapacidad, permisos por días): inicio + N días NATURALES
 *   consecutivos, incluidos sábado y domingo.
 *     fecha_fin = inicio + (N − 1)       14-oct + 5 días → 18-oct
 * - DÍAS ESPECÍFICOS (vacaciones): lista de días elegidos, sin repetidos,
 *   sin domingos (no cuentan como vacaciones en MR. LANA; el sábado sí),
 *   desde hoy, sin chocar con otras vacaciones vigentes de la persona.
 *   dias_solicitados = cuántos días; fecha_inicio/fecha_fin = primero y
 *   último (solo compatibilidad: la fuente real es la lista).
 * - HORARIO / FECHA ÚNICA: un solo día (fecha_fin = fecha_inicio).
 * - NINGUNA: sin fechas.
 *
 * Feriados: todavía no existe catálogo, así que no se descuentan (cuando
 * exista, va aquí). Compatibilidad con apps anteriores que mandan
 * fecha_inicio/fecha_fin: se convierten a la misma regla (rango →
 * duración en días naturales; rango de vacaciones → sus días sin domingo).
 */
class FechasSolicitudService
{
    /** Días máximos que se pueden pedir en una sola solicitud. */
    private const MAX_DIAS = 365;

    private const MAX_DIAS_VACACIONES = 60;

    /**
     * @param  array<string, mixed>  $datos  Validados por StoreSolicitudInternaRequest.
     * @return array<string, mixed> $datos con fecha_inicio, fecha_fin, dias_solicitados y dias_vacaciones (list<string> Y-m-d) calculados.
     *
     * @throws ValidationException
     */
    public function normalizar(TipoSolicitudInterna $tipo, array $datos, ?Colaborador $colaborador): array
    {
        $modo = $tipo->modoFechas();

        return match ($modo) {
            ModoFechasSolicitud::Duracion => $this->duracion($datos),
            ModoFechasSolicitud::DiasEspecificos => $this->diasEspecificos($datos, $colaborador),
            ModoFechasSolicitud::Horario, ModoFechasSolicitud::FechaUnica => $this->unDia($datos),
            ModoFechasSolicitud::Ninguna => [...$datos, 'fecha_inicio' => null, 'fecha_fin' => null, 'dias_solicitados' => null, 'dias_vacaciones' => []],
        };
    }

    /** fecha_fin = inicio + (días − 1), días naturales. */
    public function fechaFinPorDuracion(string $inicio, int $dias): string
    {
        return $this->fecha($inicio, 'fecha_inicio')->addDays($dias - 1)->toDateString();
    }

    public function esDiaComputableVacaciones(CarbonImmutable $dia): bool
    {
        return ! in_array($dia->dayOfWeek, $this->diasNoComputables(), true);
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function duracion(array $datos): array
    {
        $inicio = $this->fecha($datos['fecha_inicio'] ?? null, 'fecha_inicio');
        $dias = isset($datos['duracion_dias']) ? (int) $datos['duracion_dias'] : null;

        // App anterior: mandaba inicio y fin; se traduce a duración natural.
        if ($dias === null && ! empty($datos['fecha_fin'])) {
            $fin = $this->fecha($datos['fecha_fin'], 'fecha_fin');

            if ($fin->lessThan($inicio)) {
                throw ValidationException::withMessages(['fecha_fin' => 'La fecha final no puede ser anterior a la inicial.']);
            }

            $dias = (int) $inicio->diffInDays($fin) + 1;
        }

        if ($dias === null || $dias < 1 || $dias > self::MAX_DIAS) {
            throw ValidationException::withMessages(['duracion_dias' => sprintf('Indica el número de días (de 1 a %d).', self::MAX_DIAS)]);
        }

        return [
            ...$datos,
            'fecha_inicio' => $inicio->toDateString(),
            'fecha_fin' => $inicio->addDays($dias - 1)->toDateString(),
            'dias_solicitados' => $dias,
            'dias_vacaciones' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function diasEspecificos(array $datos, ?Colaborador $colaborador): array
    {
        $crudos = is_array($datos['dias'] ?? null) ? array_values($datos['dias']) : null;

        // App anterior: rango inicio–fin → sus días, sin domingos.
        if ($crudos === null && ! empty($datos['fecha_inicio']) && ! empty($datos['fecha_fin'])) {
            $crudos = [];
            $inicio = $this->fecha($datos['fecha_inicio'], 'fecha_inicio');
            $fin = $this->fecha($datos['fecha_fin'], 'fecha_fin');

            for ($dia = $inicio; $dia->lessThanOrEqualTo($fin) && count($crudos) <= self::MAX_DIAS_VACACIONES; $dia = $dia->addDay()) {
                if ($this->esDiaComputableVacaciones($dia)) {
                    $crudos[] = $dia->toDateString();
                }
            }
        }

        if ($crudos === null || $crudos === []) {
            throw ValidationException::withMessages(['dias' => 'Selecciona al menos un día de vacaciones.']);
        }

        $dias = [];

        foreach ($crudos as $valor) {
            $dia = $this->fecha(is_string($valor) ? $valor : null, 'dias');

            if (isset($dias[$dia->toDateString()])) {
                throw ValidationException::withMessages(['dias' => sprintf('El día %s está repetido.', $dia->format('d/m/Y'))]);
            }

            if (! $this->esDiaComputableVacaciones($dia)) {
                throw ValidationException::withMessages(['dias' => sprintf('El %s es domingo: los domingos no cuentan como vacaciones.', $dia->format('d/m/Y'))]);
            }

            if ($dia->lessThan(CarbonImmutable::today())) {
                throw ValidationException::withMessages(['dias' => sprintf('El %s ya pasó: elige días a partir de hoy.', $dia->format('d/m/Y'))]);
            }

            $dias[$dia->toDateString()] = $dia;
        }

        if (count($dias) > self::MAX_DIAS_VACACIONES) {
            throw ValidationException::withMessages(['dias' => sprintf('Puedes pedir a lo más %d días en una solicitud.', self::MAX_DIAS_VACACIONES)]);
        }

        ksort($dias);
        $lista = array_keys($dias);

        if ($colaborador !== null) {
            $this->exigirSinTraslape($colaborador, $lista);
        }

        return [
            ...$datos,
            'fecha_inicio' => $lista[0],
            'fecha_fin' => $lista[count($lista) - 1],
            'dias_solicitados' => count($lista),
            'dias_vacaciones' => $lista,
        ];
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function unDia(array $datos): array
    {
        $dia = $this->fecha($datos['fecha_inicio'] ?? null, 'fecha_inicio')->toDateString();

        return [...$datos, 'fecha_inicio' => $dia, 'fecha_fin' => $dia, 'dias_solicitados' => 1, 'dias_vacaciones' => []];
    }

    /**
     * Un día ya pedido o aprobado en otras vacaciones vigentes de la misma
     * persona no se puede volver a pedir (módulo unificado y legacy).
     *
     * @param  list<string>  $dias
     */
    private function exigirSinTraslape(Colaborador $colaborador, array $dias): void
    {
        $vigentes = [EstadoSolicitudInterna::Creada->value, EstadoSolicitudInterna::Enviada->value, EstadoSolicitudInterna::EnRevision->value, EstadoSolicitudInterna::RequiereCorreccion->value, EstadoSolicitudInterna::Aprobada->value];

        $ocupado = SolicitudVacacionDia::query()
            ->where('colaborador_id', $colaborador->id)
            ->whereIn('fecha', $dias)
            ->whereHas('solicitud', fn ($q) => $q->whereIn('estado', $vigentes))
            ->orderBy('fecha')
            ->value('fecha');

        if ($ocupado === null) {
            $userId = $colaborador->user?->id;
            $legacy = SolicitudVacaciones::query()
                ->where(fn ($q) => $q->where('colaborador_id', $colaborador->id)->when($userId !== null, fn ($w) => $w->orWhere('user_id', $userId)))
                ->whereIn('estado', [EstadoSolicitudVacaciones::Pendiente->value, EstadoSolicitudVacaciones::Aprobada->value])
                ->where('fecha_inicio', '<=', $dias[count($dias) - 1])
                ->where('fecha_fin', '>=', $dias[0])
                ->get(['fecha_inicio', 'fecha_fin']);

            foreach ($dias as $dia) {
                if ($legacy->contains(fn (SolicitudVacaciones $s) => $s->fecha_inicio->toDateString() <= $dia && $s->fecha_fin->toDateString() >= $dia)) {
                    $ocupado = $dia;

                    break;
                }
            }
        }

        if ($ocupado !== null) {
            throw ValidationException::withMessages(['dias' => sprintf('Ya tienes vacaciones pedidas o aprobadas el %s.', CarbonImmutable::parse((string) $ocupado)->format('d/m/Y'))]);
        }
    }

    private function fecha(mixed $valor, string $campo): CarbonImmutable
    {
        if (! is_string($valor) || preg_match('/^\d{4}-\d{2}-\d{2}/', $valor) !== 1) {
            throw ValidationException::withMessages([$campo => 'Indica una fecha válida (AAAA-MM-DD).']);
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d', substr($valor, 0, 10))->startOfDay();
        } catch (Throwable) {
            throw ValidationException::withMessages([$campo => 'Indica una fecha válida (AAAA-MM-DD).']);
        }
    }

    /**
     * Días de la semana que no cuentan como vacaciones (0 = domingo).
     *
     * @return list<int>
     */
    private function diasNoComputables(): array
    {
        return array_values(array_map('intval', (array) config('vacaciones.dias_no_computables', [0])));
    }
}
