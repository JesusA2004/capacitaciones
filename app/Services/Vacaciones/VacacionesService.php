<?php

namespace App\Services\Vacaciones;

use App\Enums\EstadoSolicitudVacaciones;
use App\Models\SolicitudVacaciones;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Calcula el saldo de vacaciones de un colaborador segun su antiguedad y la
 * tabla legal configurable (config/vacaciones.php). No hay una tabla de
 * "saldos" persistida: los dias generados se calculan a partir de
 * fecha_ingreso, y los usados/en solicitud se agregan desde
 * solicitudes_vacaciones — igual criterio de "vista calculada" que el
 * expediente digital.
 */
class VacacionesService
{
    /**
     * @return array{
     *     antiguedad_anios: int,
     *     vigencia_inicio: string|null,
     *     vigencia_fin: string|null,
     *     dias_generados: int,
     *     dias_usados: int,
     *     dias_en_solicitud: int,
     *     dias_disponibles: int,
     * }
     */
    public function saldo(User $colaborador): array
    {
        if ($colaborador->fecha_ingreso === null) {
            return [
                'antiguedad_anios' => 0,
                'vigencia_inicio' => null,
                'vigencia_fin' => null,
                'dias_generados' => 0,
                'dias_usados' => 0,
                'dias_en_solicitud' => 0,
                'dias_disponibles' => 0,
            ];
        }

        $ingreso = $colaborador->fecha_ingreso;
        $hoy = Carbon::now();
        $antiguedadAnios = (int) $ingreso->diffInYears($hoy);

        $vigenciaInicio = $ingreso->copy()->addYears($antiguedadAnios);
        $vigenciaFin = $vigenciaInicio->copy()->addYear()->subDay();

        $diasGenerados = $this->diasPorAntiguedad($antiguedadAnios);

        $solicitudesVigentes = SolicitudVacaciones::query()
            ->where('user_id', $colaborador->id)
            ->whereBetween('fecha_inicio', [$vigenciaInicio, $vigenciaFin])
            ->get();

        $diasUsados = (int) $solicitudesVigentes
            ->where('estado', EstadoSolicitudVacaciones::Aprobada)
            ->sum('dias_solicitados');

        $diasEnSolicitud = (int) $solicitudesVigentes
            ->where('estado', EstadoSolicitudVacaciones::Pendiente)
            ->sum('dias_solicitados');

        return [
            'antiguedad_anios' => $antiguedadAnios,
            'vigencia_inicio' => $vigenciaInicio->toDateString(),
            'vigencia_fin' => $vigenciaFin->toDateString(),
            'dias_generados' => $diasGenerados,
            'dias_usados' => $diasUsados,
            'dias_en_solicitud' => $diasEnSolicitud,
            'dias_disponibles' => max(0, $diasGenerados - $diasUsados - $diasEnSolicitud),
        ];
    }

    /**
     * Dias generados para un numero de anios completos de antiguedad, segun
     * la tabla legal configurable. Menos de 1 anio completo = 0 dias.
     */
    public function diasPorAntiguedad(int $antiguedadAnios): int
    {
        if ($antiguedadAnios < 1) {
            return 0;
        }

        /** @var array<int, int> $tabla */
        $tabla = config('vacaciones.tabla_dias', []);
        $ultimoAnioTabla = max(array_keys($tabla) ?: [0]);

        if ($antiguedadAnios <= $ultimoAnioTabla) {
            return $tabla[$antiguedadAnios];
        }

        $base = $tabla[$ultimoAnioTabla];
        $aniosPorBloque = (int) config('vacaciones.anios_por_bloque');
        $incrementoPorBloque = (int) config('vacaciones.incremento_por_bloque');
        $bloques = (int) ceil(($antiguedadAnios - $ultimoAnioTabla) / $aniosPorBloque);

        return $base + ($bloques * $incrementoPorBloque);
    }
}
